<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderAttachment;
use App\Models\ChangeOrderLine;
use App\Models\Job;
use App\Models\User;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\ChangeOrders\ChangeOrders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The office's Change Orders screens for the app — mobile's counterpart to
 * web's own `ChangeOrderController`, with the same rules ({@see ChangeOrderAccess}:
 * a manager sees and decides every change order of the company, a foreman
 * raises and changes their own) and the same service doing the work.
 *
 * Distinct from `JobChangeOrderController`, which is the field crew's
 * "Additional Work" on a job they are staffed on.
 */
class ChangeOrderController extends Controller
{
    use ApiResponses;

    private const PER_PAGE = 10;

    public function __construct(
        private readonly ChangeOrderAccess $access,
        private readonly ChangeOrders $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'job' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['all', ...ChangeOrder::STATUSES])],
            'source' => ['nullable', Rule::in(['all', ...ChangeOrder::SOURCES])],
        ]);
        $search = trim($filters['search'] ?? '');
        $status = $filters['status'] ?? 'all';
        $source = $filters['source'] ?? 'all';
        $job = $filters['job'] ?? null;

        $orders = $this->access->visible(ChangeOrder::query(), $user)
            ->with('job:id,name')
            ->when($search !== '', function ($q) use ($search) {
                // "CO-003", "co3" and "3" all find change order 3.
                $number = preg_match('/^(?:co)?-?0*(\d+)$/i', $search, $m) ? (int) $m[1] : null;

                return $q->where(fn ($q) => $q
                    ->where('description', 'like', "%{$search}%")
                    ->orWhereHas('job', fn ($jobs) => $jobs->where('name', 'like', "%{$search}%"))
                    ->when($number !== null, fn ($q) => $q->orWhere('number', $number)));
            })
            ->when($job !== null, fn ($q) => $q->where('job_id', $job))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($source !== 'all', fn ($q) => $q->where('source', $source))
            ->orderByDesc('number')
            ->paginate(self::PER_PAGE);

        return $this->ok([
            'orders' => $orders->getCollection()->map(fn (ChangeOrder $co) => [
                ...$this->summary($co),
                'job' => ['id' => $co->job_id, 'name' => $co->job?->name ?? '—'],
                'canEdit' => $this->access->canEdit($user, $co),
                'canDelete' => $this->access->canDelete($user, $co),
            ])->all(),
            'jobs' => $this->access->visible(ChangeOrder::query(), $user)->with('job:id,name')->get()->pluck('job')->filter()->unique('id')->sortBy('name')
                ->map(fn (Job $job) => ['id' => $job->id, 'name' => $job->name])->values()->all(),
            'canCreate' => $this->access->jobs($user)->exists(),
            'meta' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'perPage' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /** What the create form offers — web's `create()` props. */
    public function options(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok([
            'jobs' => $this->access->jobs($user)->whereNotIn('status', [Job::STATUS_COMPLETED])->with('clientRecord:id,labor_rate')->orderBy('name')->get()
                ->map(fn (Job $job) => [
                    'id' => $job->id,
                    'name' => $job->name,
                    'client' => $job->client,
                    'laborRate' => $job->clientRecord?->labor_rate === null ? null : (float) $job->clientRecord->labor_rate,
                ])->all(),
            'reasons' => $this->reasonOptions(),
            'isManager' => $this->access->isManager($user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request);

        $job = Job::query()->ownedBy($user)->findOrFail($request->validate(['job_id' => ['required', 'integer']], ['job_id.required' => 'Pick the job this work was added to.'])['job_id']);
        abort_unless($this->access->canRaiseFor($user, $job), 403, 'You cannot raise a change order for this job.');

        $co = $this->orders->create($user, $job, $data, $data['lines']);

        if ($request->boolean('submit')) {
            $this->orders->submit($co, $user);

            return $this->created($this->detail($user, $co), "{$co->label()} was submitted for approval.");
        }

        return $this->created($this->detail($user, $co), "{$co->label()} was saved as a draft.");
    }

    public function show(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);

        return $this->ok($this->detail($user, $this->find($user, $changeOrder)));
    }

    public function update(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co), 403, 'This change order can no longer be changed.');

        $data = $this->validated($request);
        $this->orders->update($co, $user, $data, $data['lines']);

        if ($request->boolean('submit')) {
            $this->orders->submit($co->refresh(), $user);

            return $this->ok($this->detail($user, $co), "{$co->label()} was submitted for approval.");
        }

        return $this->ok($this->detail($user, $co), "{$co->label()} was saved.");
    }

    public function destroy(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDelete($user, $co), 403, 'Only drafts can be deleted.');

        $co->attachments->each->deleteWithFile();
        $label = $co->label();
        $co->delete();

        return $this->ok(null, "{$label} was deleted.");
    }

    public function submit(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co), 403, 'This change order can no longer be submitted.');

        $this->orders->submit($co, $user);

        return $this->ok($this->detail($user, $co), "{$co->label()} was submitted for approval.");
    }

    public function withdraw(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canWithdraw($user, $co), 403, 'This change order cannot be withdrawn.');

        $this->orders->withdraw($co, $user);

        return $this->ok($this->detail($user, $co), "{$co->label()} is back in draft.");
    }

    public function approve(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDecide($user, $co), 403, 'Only a manager can decide a submitted change order.');

        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $this->orders->approve($co, $user, $note);

        return $this->ok($this->detail($user, $co), "{$co->label()} was approved. Its amount is on the job's billing.");
    }

    public function reject(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDecide($user, $co), 403, 'Only a manager can decide a submitted change order.');

        $note = $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Say why it is rejected, so it can be corrected.'])['note'];
        $this->orders->reject($co, $user, $note);

        return $this->ok($this->detail($user, $co), "{$co->label()} was rejected.");
    }

    public function attach(Request $request, int $changeOrder): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        // Evidence can be added while the change order is open to changes, or waiting on a decision.
        abort_unless($this->access->canEdit($user, $co) || $this->access->canWithdraw($user, $co), 403, 'Evidence can no longer be added to this change order.');

        $request->validate([
            'attachments' => ['required', 'array', 'max:10'],
            'attachments.*' => ['file', Rule::file()->extensions(config('documents.extensions')), 'max:20480'],
        ], [
            'attachments.max' => 'Attach up to 10 files at a time.',
            'attachments.*.extensions' => 'That file type is not accepted.',
            'attachments.*.max' => 'Files must be 20 MB or smaller.',
        ]);

        foreach ($request->file('attachments', []) as $file) {
            $co->attachments()->create([
                'user_id' => $user->id,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("change-orders/{$co->id}", 'local'),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ]);
        }

        return $this->ok($this->detail($user, $co), 'Evidence added.');
    }

    public function download(Request $request, int $changeOrder, int $attachment): StreamedResponse
    {
        $co = $this->find($this->user($request), $changeOrder);
        $file = $co->attachments()->findOrFail($attachment);

        return response()->streamDownload(
            fn () => print (Storage::disk('local')->get($file->path)),
            $file->name,
            ['Content-Type' => $file->mime ?? 'application/octet-stream'],
        );
    }

    public function detach(Request $request, int $changeOrder, int $attachment): JsonResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co) || $this->access->canWithdraw($user, $co), 403, 'Evidence can no longer be removed from this change order.');

        $file = $co->attachments()->findOrFail($attachment);
        $name = $file->name;
        $file->deleteWithFile();

        return $this->ok($this->detail($user, $co), "“{$name}” was removed.");
    }

    // ------------------------------------------------------------------ helpers --

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($this->access->canUse($user), 403, 'Only a foreman or manager can use change orders.');

        return $user;
    }

    private function find(User $user, int $id): ChangeOrder
    {
        return $this->access->visible(ChangeOrder::query(), $user)->findOrFail($id);
    }

    /** @return list<array{value: string, label: string}> */
    private function reasonOptions(): array
    {
        return collect(ChangeOrder::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    /** @return array<string, mixed> */
    private function summary(ChangeOrder $co): array
    {
        return [
            'id' => $co->id,
            'label' => $co->label(),
            'description' => $co->description,
            'source' => $co->source,
            'status' => $co->status,
            'reasonCode' => $co->reason_code,
            'reasonLabel' => $co->reasonLabel(),
            'customerRequested' => (bool) $co->customer_requested,
            'laborHours' => (float) $co->labor_hours,
            'materialCost' => (float) $co->material_cost,
            'amount' => (float) $co->sell_total,
        ];
    }

    /** One change order in full — web's `show()` props. @return array<string, mixed> */
    private function detail(User $user, ChangeOrder $co): array
    {
        $co->refresh()->load(['job:id,name,client,status', 'author:id,name', 'decider:id,name', 'lines', 'attachments', 'events.user:id,name']);

        return [
            ...$this->summary($co),
            'job' => ['id' => $co->job->id, 'name' => $co->job->name, 'client' => $co->job->client],
            'reason' => $co->reason,
            'markupPct' => (float) $co->markup_pct,
            'laborCost' => (float) $co->labor_cost,
            'costTotal' => (float) $co->cost_total,
            'author' => $co->author?->name,
            'submittedAt' => $co->submitted_at?->toISOString(),
            'decidedAt' => $co->decided_at?->toISOString(),
            'decidedBy' => $co->decider?->name,
            'decisionNote' => $co->decision_note,
            'createdAt' => $co->created_at->toISOString(),
            'lines' => $co->lines->map(fn (ChangeOrderLine $line) => [
                'id' => $line->id,
                'kind' => $line->kind,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unitCost' => (float) $line->unit_cost,
                'total' => (float) $line->total,
            ])->all(),
            'attachments' => $co->attachments->map(fn (ChangeOrderAttachment $file) => [
                'id' => $file->id,
                'name' => $file->name,
                'size' => (int) $file->size,
                'mime' => $file->mime,
                'at' => $file->created_at->toISOString(),
            ])->all(),
            'history' => $co->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'note' => $event->note,
                'amount' => $event->sell_total === null ? null : (float) $event->sell_total,
                'by' => $event->user?->name,
                'at' => $event->created_at->toISOString(),
            ])->all(),
            'billing' => $this->billing($co),
            'can' => [
                'edit' => $this->access->canEdit($user, $co),
                'submit' => $this->access->canEdit($user, $co) && $co->lines->isNotEmpty(),
                'withdraw' => $this->access->canWithdraw($user, $co),
                'decide' => $this->access->canDecide($user, $co),
                'delete' => $this->access->canDelete($user, $co),
            ],
        ];
    }

    /** Whether the amount is on the job's billing, and on which invoices. @return array<string, mixed> */
    private function billing(ChangeOrder $co): array
    {
        if ($co->status !== ChangeOrder::STATUS_APPROVED) {
            return ['state' => 'none', 'invoices' => []];
        }

        $invoices = $co->invoiceItems()->with('invoice:id,invoice_number,status')->get()->pluck('invoice')->filter()
            ->map(fn ($invoice) => ['id' => $invoice->id, 'number' => $invoice->invoice_number, 'status' => $invoice->status])->values()->all();

        return ['state' => $invoices === [] ? 'pending' : 'billed', 'invoices' => $invoices];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_code' => ['nullable', Rule::in(array_keys(ChangeOrder::REASONS))],
            'customer_requested' => ['nullable', 'boolean'],
            'source' => ['nullable', Rule::in(ChangeOrder::SOURCES)],
            'markup_pct' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.kind' => ['required', Rule::in([ChangeOrderLine::MATERIAL, ChangeOrderLine::LABOR])],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit' => ['nullable', 'string', 'max:16'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ], [
            'description.required' => 'Describe the change.',
            'lines.required' => 'Add at least one material or labor line.',
            'lines.min' => 'Add at least one material or labor line.',
            'lines.*.description.required' => 'Name this line.',
            'lines.*.quantity.required' => 'Enter a quantity.',
            'lines.*.quantity.gt' => 'The quantity must be more than zero.',
            'lines.*.unit_cost.required' => 'Enter a cost.',
        ]);
    }
}
