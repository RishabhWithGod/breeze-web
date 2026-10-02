<?php

namespace App\Http\Controllers;

use App\Models\ChangeOrder;
use App\Models\ChangeOrderAttachment;
use App\Models\ChangeOrderLine;
use App\Models\Job;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\ChangeOrders\ChangeOrders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Change orders: added work, materials or labor documented after a job begins.
 *
 * Made by hand — no drawing is needed — with material and labor lines, evidence attached, then
 * submitted and approved or rejected by a manager. Approving puts the amount on the job's billing.
 * See {@see ChangeOrderAccess} for who may do what.
 */
class ChangeOrderController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly ChangeOrderAccess $access,
        private readonly ChangeOrders $orders,
    ) {}

    public function index(Request $request): Response
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
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('ChangeOrders', [
            'orders' => $orders->getCollection()->map(fn (ChangeOrder $co) => [
                'id' => $co->id,
                'label' => $co->label(),
                'job' => ['id' => $co->job_id, 'name' => $co->job?->name ?? '—'],
                'description' => $co->description,
                'reasonLabel' => $co->reasonLabel(),
                'customerRequested' => (bool) $co->customer_requested,
                'source' => $co->source,
                'laborHours' => (float) $co->labor_hours,
                'materialCost' => (float) $co->material_cost,
                'amount' => (float) $co->sell_total,
                'status' => $co->status,
                'canEdit' => $this->access->canEdit($user, $co),
                'canDelete' => $this->access->canDelete($user, $co),
            ])->all(),
            'page' => ['current' => $orders->currentPage(), 'last' => $orders->lastPage(), 'total' => $orders->total(), 'from' => $orders->firstItem() ?? 0, 'to' => $orders->lastItem() ?? 0],
            'filters' => ['search' => $search, 'job' => $job === null ? null : (int) $job, 'status' => $status, 'source' => $source],
            'jobs' => $this->access->visible(ChangeOrder::query(), $user)->with('job:id,name')->get()->pluck('job')->filter()->unique('id')->sortBy('name')
                ->map(fn (Job $job) => ['id' => $job->id, 'name' => $job->name])->values()->all(),
            'canCreate' => $this->access->jobs($user)->exists(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('ChangeOrderForm', [
            'changeOrder' => null,
            'jobs' => $this->jobOptions($user),
            'preselectedJob' => $request->integer('job') ?: null,
            'isManager' => $this->access->isManager($user),
            'reasons' => $this->reasonOptions(),
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    private function reasonOptions(): array
    {
        return collect(ChangeOrder::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $this->validated($request);

        $job = Job::query()->ownedBy($user)->findOrFail($request->validate(['job_id' => ['required', 'integer']])['job_id']);
        abort_unless($this->access->canRaiseFor($user, $job), 403);

        $co = $this->orders->create($user, $job, $data, $data['lines']);
        $this->storeFiles($request, $co);

        if ($request->boolean('submit')) {
            $this->orders->submit($co, $user);

            return redirect()->route('change-orders.show', $co)->with('success', "{$co->label()} was submitted for approval.");
        }

        return redirect()->route('change-orders.show', $co)->with('success', "{$co->label()} was saved as a draft.");
    }

    public function show(Request $request, int $changeOrder): Response
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder)->load(['job:id,name,client,status', 'author:id,name', 'decider:id,name', 'lines', 'attachments', 'events.user:id,name']);

        return Inertia::render('ChangeOrderShow', [
            'changeOrder' => [
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
            ],
        ]);
    }

    public function edit(Request $request, int $changeOrder): Response
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder)->load(['job:id,name,client', 'lines', 'attachments']);
        abort_unless($this->access->canEdit($user, $co), 403);

        return Inertia::render('ChangeOrderForm', [
            'changeOrder' => [
                ...$this->summary($co),
                'job' => ['id' => $co->job->id, 'name' => $co->job->name],
                'reason' => $co->reason,
                'markupPct' => (float) $co->markup_pct,
                'lines' => $co->lines->map(fn (ChangeOrderLine $line) => [
                    'kind' => $line->kind,
                    'description' => $line->description,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unitCost' => (float) $line->unit_cost,
                ])->all(),
                'attachments' => $co->attachments->map(fn (ChangeOrderAttachment $file) => ['id' => $file->id, 'name' => $file->name, 'size' => (int) $file->size])->all(),
                'rejection' => $co->status === ChangeOrder::STATUS_REJECTED ? $co->decision_note : null,
            ],
            'reasons' => $this->reasonOptions(),
            'jobs' => [],
            'preselectedJob' => null,
            'isManager' => $this->access->isManager($user),
        ]);
    }

    public function update(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co), 403);

        $data = $this->validated($request);
        $this->orders->update($co, $user, $data, $data['lines']);
        $this->storeFiles($request, $co);

        if ($request->boolean('submit')) {
            $this->orders->submit($co->refresh(), $user);

            return redirect()->route('change-orders.show', $co)->with('success', "{$co->label()} was submitted for approval.");
        }

        return redirect()->route('change-orders.show', $co)->with('success', "{$co->label()} was saved.");
    }

    public function destroy(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDelete($user, $co), 403);

        $co->attachments->each->deleteWithFile();
        $label = $co->label();
        $co->delete();

        return redirect()->route('change-orders.index')->with('warning', "{$label} was deleted.");
    }

    public function submit(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co), 403);

        $this->orders->submit($co, $user);

        return back()->with('success', "{$co->label()} was submitted for approval.");
    }

    public function withdraw(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canWithdraw($user, $co), 403);

        $this->orders->withdraw($co, $user);

        return back()->with('success', "{$co->label()} is back in draft.");
    }

    public function approve(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDecide($user, $co), 403);

        $note = $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null;
        $this->orders->approve($co, $user, $note);

        // Back to the list, which then shows the new status.
        return redirect()->route('change-orders.index')->with('success', "{$co->label()} was approved. Its amount is on the job's billing.");
    }

    public function reject(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canDecide($user, $co), 403);

        $note = $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Say why it is rejected, so it can be corrected.'])['note'];
        $this->orders->reject($co, $user, $note);

        return redirect()->route('change-orders.index')->with('warning', "{$co->label()} was rejected.");
    }

    public function attach(Request $request, int $changeOrder): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        // Evidence can be added while the change order is open to changes, or waiting on a decision.
        abort_unless($this->access->canEdit($user, $co) || $this->access->canWithdraw($user, $co), 403);

        $request->validate($this->fileRules(), $this->fileMessages());
        $this->storeFiles($request, $co);

        return back()->with('success', 'Evidence added.');
    }

    public function download(Request $request, int $changeOrder, int $attachment): StreamedResponse
    {
        $co = $this->find($this->user($request), $changeOrder);
        $file = $co->attachments()->findOrFail($attachment);

        return response()->streamDownload(fn () => print (Storage::disk('local')->get($file->path)), $file->name);
    }

    public function detach(Request $request, int $changeOrder, int $attachment): RedirectResponse
    {
        $user = $this->user($request);
        $co = $this->find($user, $changeOrder);
        abort_unless($this->access->canEdit($user, $co) || $this->access->canWithdraw($user, $co), 403);

        $file = $co->attachments()->findOrFail($attachment);
        $name = $file->name;
        $file->deleteWithFile();

        return back()->with('warning', "“{$name}” was removed.");
    }

    // ------------------------------------------------------------------ helpers --

    private function user(Request $request)
    {
        $user = $request->user();
        abort_unless($this->access->canUse($user), 403);

        return $user;
    }

    private function find($user, int $id): ChangeOrder
    {
        return $this->access->visible(ChangeOrder::query(), $user)->findOrFail($id);
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

    /** @return list<array<string, mixed>> */
    private function jobOptions($user): array
    {
        return $this->access->jobs($user)->whereNotIn('status', [Job::STATUS_COMPLETED])->with('clientRecord:id,labor_rate')->orderBy('name')->get()
            ->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                // What the client's own labor is billed at, to start a labor line from.
                'laborRate' => $job->clientRecord?->labor_rate === null ? null : (float) $job->clientRecord->labor_rate,
            ])->all();
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
            ...$this->fileRules(),
        ], [
            'description.required' => 'Describe the change.',
            'lines.required' => 'Add at least one material or labor line.',
            'lines.min' => 'Add at least one material or labor line.',
            'lines.*.description.required' => 'Name this line.',
            'lines.*.quantity.required' => 'Enter a quantity.',
            'lines.*.quantity.gt' => 'The quantity must be more than zero.',
            'lines.*.unit_cost.required' => 'Enter a cost.',
            ...$this->fileMessages(),
        ]);
    }

    /** @return array<string, mixed> */
    private function fileRules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', Rule::file()->extensions(config('documents.extensions')), 'max:20480'],
        ];
    }

    /** @return array<string, string> */
    private function fileMessages(): array
    {
        return [
            'attachments.max' => 'Attach up to 10 files at a time.',
            'attachments.*.extensions' => 'That file type is not accepted.',
            'attachments.*.max' => 'Files must be 20 MB or smaller.',
        ];
    }

    private function storeFiles(Request $request, ChangeOrder $co): void
    {
        foreach ($request->file('attachments', []) as $file) {
            $co->attachments()->create([
                'user_id' => $request->user()->id,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("change-orders/{$co->id}", 'local'),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ]);
        }
    }
}
