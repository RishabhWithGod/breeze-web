<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\ChangeOrderLine;
use App\Models\Job;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\ChangeOrders\ChangeOrders;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Additional Work" on a job's own screen — the change orders raised against
 * it. Anyone staffed on the job can read the list; only a foreman or a
 * manager can raise one (the same rule web's change-order screens use), and
 * dollar amounts go only to managers: a crew member reads what the extra work
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

        $manager = $this->access->isManager($request->user());

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

        $manager = $this->access->isManager($request->user());

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.kind' => ['required', Rule::in([ChangeOrderLine::MATERIAL, ChangeOrderLine::LABOR])],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'lines.*.unit' => ['nullable', 'string', 'max:16'],
            // Pricing is the office's job; only a manager enters it from here.
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
            'lineCount' => (int) ($co->lines_count ?? 0),
            'laborHours' => (float) $co->labor_hours,
            'author' => $co->author?->name,
            'createdAt' => $co->created_at?->toISOString(),
            'decisionNote' => $co->decision_note,
            ...($withAmounts ? [
                'materialCost' => (float) $co->material_cost,
                'amount' => (float) $co->sell_total,
            ] : []),
        ];
    }
}
