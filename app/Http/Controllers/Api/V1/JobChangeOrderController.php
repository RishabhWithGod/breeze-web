<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderAttachment;
use App\Models\ChangeOrderLine;
use App\Models\Job;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\ChangeOrders\ChangeOrders;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Additional Work" on a job's own screen — the change orders raised against
 * it. Anyone staffed on the job can read the list; only a foreman or a
 * manager can raise one (the same rule web's change-order screens use), and
 * dollar amounts go only to a foreman or manager: a crew member reads what the extra work
 * is, never what it costs or sells for.
 */
class JobChangeOrderController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $jobAccess,
        private readonly ChangeOrderAccess $access,
        private readonly ChangeOrders $orders,
    ) {}

    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->jobAccess->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $manager = $request->user()->hasForemanAuthority();

        $orders = ChangeOrder::query()
            ->where('job_id', $job->id)
            ->with('author:id,name')
            ->withCount('lines')
            ->orderByDesc('number')
            ->get();

        return $this->ok([
            'changeOrders' => $orders->map(fn (ChangeOrder $co) => $this->present($co, $manager))->all(),
            'canCreate' => $this->canRaise($request, $job),
        ]);
    }

    public function store(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->jobAccess->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_unless($request->user()->hasForemanAuthority(), 403, 'Only a foreman or manager can raise additional work.');
        abort_if($job->isLocked(), 409, 'This job is completed and locked.');

        $manager = true; // a foreman prices the lines too; the office reviews them on approval.

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_code' => ['nullable', Rule::in(array_keys(ChangeOrder::REASONS))],
            'customer_requested' => ['nullable', 'boolean'],
            // Set by the phone, so a change order saved offline and replayed is created once.
            'client_key' => ['nullable', 'string', 'max:64'],
            'submit' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.kind' => ['required', Rule::in([ChangeOrderLine::MATERIAL, ChangeOrderLine::LABOR])],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit' => ['nullable', 'string', 'max:16'],
            // Unit price (material) or hourly rate (labor).
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ], [
            'description.required' => 'Describe the additional work.',
            'lines.required' => 'Add at least one material or labor line.',
            'lines.min' => 'Add at least one material or labor line.',
            'lines.*.description.required' => 'Name this line.',
            'lines.*.quantity.required' => 'Enter a quantity.',
            'lines.*.quantity.gt' => 'The quantity must be more than zero.',
        ]);

        $lines = array_map(fn (array $line) => [
            ...$line,
            'unit_cost' => $manager ? ($line['unit_cost'] ?? 0) : 0,
        ], $data['lines']);

        $existing = isset($data['client_key'])
            ? ChangeOrder::query()->where('owner_id', $this->access->ownerId($request->user()))->where('client_key', $data['client_key'])->first()
            : null;
        if ($existing !== null) {
            $existing->load('author:id,name')->loadCount('lines');

            return $this->ok($this->present($existing, $manager), "{$existing->label()} was already saved.");
        }

        $co = $this->orders->create($request->user(), $job, $data, $lines);

        if ($request->boolean('submit')) {
            $this->orders->submit($co, $request->user());
        }

        $co->load('author:id,name')->loadCount('lines');

        return $this->created(
            $this->present($co->refresh()->load('author:id,name')->loadCount('lines'), $manager),
            $request->boolean('submit') ? "{$co->label()} was submitted for approval." : "{$co->label()} was saved as a draft.",
        );
    }

    /**
     * Every change order the signed-in foreman or manager may see, across jobs, plus what the
     * "new change order" form needs: the jobs it can be raised for and the reasons to pick from.
     */
    public function hub(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasForemanAuthority(), 403, 'Only a foreman or manager can use change orders.');
        $jobs = $this->jobAccess->assignedJobsQuery($user)->whereNotIn('status', [Job::STATUS_COMPLETED])->whereNull('archived_at');

        $orders = $this->access->visible(ChangeOrder::query(), $user)
            ->with(['author:id,name', 'job:id,name'])
            ->withCount('lines')
            ->orderByDesc('number')
            ->limit(200)
            ->get();

        return $this->ok([
            'changeOrders' => $orders->map(fn (ChangeOrder $co) => [
                ...$this->present($co, true),
                'jobName' => $co->job?->name,
            ])->all(),
            'jobs' => (clone $jobs)->orderBy('name')->get(['id', 'name', 'client'])
                ->map(fn (Job $job) => ['id' => $job->id, 'name' => $job->name, 'client' => $job->client])->all(),
            'reasons' => collect(ChangeOrder::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'canCreate' => $jobs->exists(),
        ]);
    }

    /** Evidence for a change order the phone has only a `client_key` for yet (saved offline, then replayed). */
    public function attachByKey(Request $request, string $key): JsonResponse
    {
        $co = ChangeOrder::query()
            ->where('owner_id', $this->access->ownerId($request->user()))
            ->where('client_key', $key)
            ->firstOrFail();

        return $this->attach($request, $co->job, $co);
    }

    /** One change order in full: its lines, evidence and history. */
    public function show(Request $request, Job $job, ChangeOrder $changeOrder): JsonResponse
    {
        $co = $this->forJob($request, $job, $changeOrder);
        $withAmounts = $request->user()->hasForemanAuthority();
        $co->load(['author:id,name', 'lines', 'attachments', 'events.user:id,name'])->loadCount('lines');

        return $this->ok([
            ...$this->present($co, $withAmounts),
            'jobName' => $job->name,
            'markupPct' => $withAmounts ? (float) $co->markup_pct : null,
            'createdAt' => $co->created_at?->toISOString(),
            'canAttach' => $this->canAttach($request, $job, $co),
            'lines' => $co->lines->map(fn ($line) => [
                'id' => $line->id,
                'kind' => $line->kind,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                ...($withAmounts ? [
                    'unitCost' => (float) $line->unit_cost,
                    'total' => (float) $line->total,
                ] : []),
            ])->all(),
            'attachments' => $co->attachments->map(fn (ChangeOrderAttachment $file) => [
                'id' => $file->id,
                'name' => $file->name,
                'size' => (int) $file->size,
                'mime' => $file->mime,
                'at' => $file->created_at?->toISOString(),
            ])->all(),
            'history' => $co->events->map(fn ($event) => [
                'id' => $event->id,
                'type' => $event->type,
                'note' => $event->note,
                'by' => $event->user?->name,
                'at' => $event->created_at?->toISOString(),
                ...($withAmounts ? ['amount' => $event->sell_total === null ? null : (float) $event->sell_total] : []),
            ])->all(),
        ]);
    }

    /** Photos or documents kept as evidence, while the change order is not yet decided. */
    public function attach(Request $request, Job $job, ChangeOrder $changeOrder): JsonResponse
    {
        $co = $this->forJob($request, $job, $changeOrder);
        abort_unless($this->canAttach($request, $job, $co), 403, 'Evidence can no longer be added to this change order.');

        $request->validate([
            'attachments' => ['required', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'],
        ], ['attachments.max' => 'Attach up to 10 files at a time.']);

        foreach ($request->file('attachments', []) as $file) {
            $co->attachments()->create([
                'user_id' => $request->user()->id,
                'name' => $file->getClientOriginalName(),
                'path' => $file->store("change-orders/{$co->id}", 'local'),
                'size' => $file->getSize(),
                'mime' => $file->getClientMimeType(),
            ]);
        }

        return $this->show($request, $job, $co);
    }

    public function download(Request $request, Job $job, ChangeOrder $changeOrder, int $attachment): StreamedResponse
    {
        $co = $this->forJob($request, $job, $changeOrder);
        $file = $co->attachments()->findOrFail($attachment);

        return response()->streamDownload(fn () => print (Storage::disk('local')->get($file->path)), $file->name);
    }

    private function forJob(Request $request, Job $job, ChangeOrder $co): ChangeOrder
    {
        abort_unless($this->jobAccess->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_unless($co->job_id === $job->id, 404);

        return $co;
    }

    private function canAttach(Request $request, Job $job, ChangeOrder $co): bool
    {
        return $request->user()->hasForemanAuthority()
            && ! $job->isLocked()
            && $co->status !== ChangeOrder::STATUS_APPROVED;
    }

    private function canRaise(Request $request, Job $job): bool
    {
        return $request->user()->hasForemanAuthority() && ! $job->isLocked();
    }

    /** @return array<string, mixed> */
    private function present(ChangeOrder $co, bool $withAmounts): array
    {
        return [
            'id' => $co->id,
            'label' => $co->label(),
            'description' => $co->description,
            'reason' => $co->reason,
            'status' => $co->status,
            'source' => $co->source,
            'reasonCode' => $co->reason_code,
            'reasonLabel' => $co->reasonLabel(),
            'customerRequested' => (bool) $co->customer_requested,
            'jobId' => $co->job_id,
            'lineCount' => (int) ($co->lines_count ?? 0),
            'laborHours' => (float) $co->labor_hours,
            'author' => $co->author?->name,
            'createdAt' => $co->created_at?->toISOString(),
            'decisionNote' => $co->decision_note,
            ...($withAmounts ? [
                'materialCost' => (float) $co->material_cost,
                'laborCost' => (float) $co->labor_cost,
                'costTotal' => (float) $co->cost_total,
                'markupPct' => (float) $co->markup_pct,
                'amount' => (float) $co->sell_total,
            ] : []),
        ];
    }
}
