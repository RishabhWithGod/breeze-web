<?php

namespace App\Services\JobCosting;

use App\Models\AnalyticsSetting;
use App\Models\JobCostEntry;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\JobCostingPolicy;
use App\Services\JobScope\JobScopeHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The Analytics dashboard's data: filters, the jobs they pick, each job's
 * costing row, and everything rolled up from them. One place builds it for
 * every screen — the web page, the phone — so they show the same numbers.
 */
class AnalyticsDashboard
{
    public const TABS = ['overview', 'profitability', 'labor', 'materials', 'billing', 'alerts'];

    public function __construct(
        private readonly JobCostSummary $costSummary,
        private readonly AnalyticsReport $report,
    ) {}

    /**
     * Everything the Analytics screens show, as plain data — the web page and the
     * phone both render this, so the two can never disagree.
     *
     * @param  array<string, mixed>  $filters  from `filters()`
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters): array
    {
        $canViewCosts = (bool) $user->can('viewJobCosts', TimeEntry::class);

        $jobs = $this->filteredJobs($filters);
        $rows = $this->rowsFor($jobs, $filters);

        $costType = $filters['cost_type'];
        $overrunRows = $costType === 'all'
            ? $rows->filter(fn (array $r) => $r['isOverBudget'])
            : $rows->filter(fn (array $r) => $this->matchesCostType($r, $costType));
        $settings = AnalyticsSetting::current();
        $redact = fn (array $row) => $canViewCosts ? $row : JobCostSummary::redact($row);

        $laborTotals = [
            'estimatedHours' => round($rows->sum('estimatedLaborHours'), 2),
            'actualHours' => round($rows->sum('actualLaborHours'), 2),
            'estimatedCost' => $canViewCosts ? round($rows->sum('estimatedLaborCost'), 2) : null,
            'actualCost' => $canViewCosts ? round($rows->sum('actualLaborCost'), 2) : null,
        ];
        $materialTotals = [
            'estimatedCost' => $canViewCosts ? round($rows->sum('estimatedMaterialCost'), 2) : null,
            'actualCost' => $canViewCosts ? round($rows->sum('actualMaterialCost'), 2) : null,
        ];

        $revenue = round($rows->sum('revenue'), 2);
        $totalCost = round($rows->sum('actualTotalCost'), 2);
        $profit = round($rows->sum('profit'), 2);

        $billing = $canViewCosts ? [
            'billed' => round($rows->sum('billed'), 2),
            'paid' => round($rows->sum('paid'), 2),
            'outstanding' => round($rows->sum('outstanding'), 2),
            'unbilled' => round($rows->sum('unbilled'), 2),
        ] : ['billed' => null, 'paid' => null, 'outstanding' => null, 'unbilled' => null];

        $jobsByStatus = collect(Job::STATUSES)
            ->map(fn (string $status) => ['status' => $status, 'count' => $jobs->where('status', $status)->count()])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values();

        $tab = $filters['tab'];
        $costBreakdown = $canViewCosts ? collect([
            ['key' => 'labor', 'label' => 'Labor', 'estimated' => $rows->sum('estimatedLaborCost'), 'actual' => $rows->sum('actualLaborCost')],
            ['key' => 'material', 'label' => 'Materials', 'estimated' => $rows->sum('estimatedMaterialCost'), 'actual' => $rows->sum('actualMaterialCost')],
            ['key' => 'equipment', 'label' => 'Equipment', 'estimated' => $rows->sum('estimatedEquipmentCost'), 'actual' => $rows->sum('actualEquipmentCost')],
            ['key' => 'other', 'label' => 'Other', 'estimated' => $rows->sum('estimatedOtherCost'), 'actual' => $rows->sum('actualOtherCost')],
        ])->map(fn (array $c) => [
            ...$c,
            'estimated' => round($c['estimated'], 2),
            'actual' => round($c['actual'], 2),
            'variance' => round($c['actual'] - $c['estimated'], 2),
            'variancePct' => $c['estimated'] > 0 ? round(($c['actual'] - $c['estimated']) / $c['estimated'] * 100, 1) : null,
        ])->all() : [];

        $selectedCost = $canViewCosts ? round(match ($costType) {
            'labor' => $rows->sum('actualLaborCost'),
            'material' => $rows->sum('actualMaterialCost'),
            'equipment' => $rows->sum('actualEquipmentCost'),
            'other' => $rows->sum('actualOtherCost'),
            default => $rows->sum('actualTotalCost'),
        }, 2) : null;

        $alerts = in_array($tab, ['overview', 'alerts'], true)
            ? $this->report->alerts($rows, $jobs, $settings, $canViewCosts)
            : [];

        return [
            'tab' => $tab,
            'settings' => $settings->only(['low_margin_pct', 'hours_warning_pct', 'stale_timer_hours', 'overdue_grace_days']),
            'can' => app(JobCostingPolicy::class)->abilities($user),
            'comparison' => $tab === 'overview' ? $this->comparison($jobs, $filters) : null,
            'costBreakdown' => $costBreakdown,
            'selectedCost' => $selectedCost,
            'trend' => in_array($tab, ['overview', 'billing'], true)
                ? $this->report->trend($jobs, $filters['date_from'], $filters['date_to'], $filters['team_member'])
                : null,
            'alerts' => $alerts,
            'labor' => $tab === 'labor'
                ? $this->report->labor($jobs, $filters['date_from'], $filters['date_to'], $filters['team_member'], $canViewCosts)
                : null,
            'billingReport' => $tab === 'billing' && $canViewCosts ? $this->report->billing($jobs, $settings) : null,
            'jobRows' => in_array($tab, ['profitability', 'materials', 'labor'], true)
                ? $rows->values()->map($redact)
                : null,
            'filters' => $filters,
            'canViewCosts' => $canViewCosts,
            'laborTotals' => $laborTotals,
            'materialTotals' => $materialTotals,
            'profitLoss' => $canViewCosts ? [
                'revenue' => $revenue,
                'totalCost' => $totalCost,
                'profit' => $profit,
                'marginPct' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            ] : ['revenue' => null, 'totalCost' => null, 'profit' => null, 'marginPct' => null],
            'scope' => [
                'baseline' => $canViewCosts ? round($rows->sum(fn (array $r) => $r['baselineTotal'] ?? $r['currentScopeTotal']), 2) : null,
                'current' => $canViewCosts ? round($rows->sum('currentScopeTotal'), 2) : null,
                'change' => $canViewCosts ? round($rows->sum('scopeChange'), 2) : null,
                'finalJobs' => $rows->where('isFinal', true)->count(),
                'liveJobs' => $rows->where('isFinal', false)->count(),
            ],
            'billing' => $billing,
            'overBudgetCount' => $overrunRows->count(),
            'overrunAlerts' => $overrunRows->sortByDesc('overrunAmount')->take(10)->values()
                ->map(fn (array $row) => $canViewCosts ? $row : JobCostSummary::redact($row)),
            'jobsByStatus' => $jobsByStatus,
            'topProfitable' => $rows->sortByDesc('profit')->take(5)->values()
                ->map(fn (array $row) => $canViewCosts ? $row : JobCostSummary::redact($row)),
            'leastProfitable' => $rows->sortBy('profit')->take(5)->values()
                ->map(fn (array $row) => $canViewCosts ? $row : JobCostSummary::redact($row)),
            'jobCount' => $jobs->count(),
            'clients' => Job::query()->whereNotNull('client')->distinct()->orderBy('client')->pluck('client'),
            'jobs' => Job::query()->active()->orderBy('name')->get(['id', 'name', 'client']),
            'teamMembers' => TeamMember::orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * One job's Job Costing screen as plain data — all-time unless a range is given.
     *
     * @param  array{from?: ?string, to?: ?string}  $data
     * @return array<string, mixed>
     */
    public function jobDetail(User $user, Job $job, array $data): array
    {
        $canViewCosts = (bool) $user->can('viewJobCosts', TimeEntry::class);
        $summary = $this->costSummary->for($job, $data['from'] ?? null, $data['to'] ?? null);

        $job->load(['estimates', 'schedule']);

        $laborRows = $job->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->with(['teamMember', 'user'])
            ->get()
            // Grouped by `user_id`, never `team_member_id` — every entry has a
            // real owning user, while the member link is nullable and would
            // otherwise split one person across two rows.
            ->groupBy(fn ($entry) => $entry->user_id)
            ->map(function ($entries) {
                $first = $entries->first();

                return [
                    'name' => $first->teamMember?->name ?? $first->user?->name ?? 'Unknown',
                    'role' => $first->teamMember?->role ?? $first->user?->role,
                    'hours' => round((float) $entries->sum('hours'), 2),
                    'regularHours' => round((float) $entries->sum('regular_hours'), 2),
                    'overtimeHours' => round((float) $entries->sum('overtime_hours'), 2),
                    'billableHours' => round((float) $entries->where('billable', true)->sum('hours'), 2),
                    'laborRate' => $entries->last()->cost_rate !== null ? (float) $entries->last()->cost_rate : null,
                    'laborCost' => round((float) $entries->sum('labor_cost'), 2),
                ];
            })
            ->sortByDesc('hours')
            ->values();

        $costEntries = $job->costEntries()->with('recorder')->get()->map(fn (JobCostEntry $entry) => [
            'id' => $entry->id,
            'category' => $entry->category,
            'description' => $entry->description,
            'quantity' => $entry->quantity !== null ? (float) $entry->quantity : null,
            'unitCost' => $entry->unit_cost !== null ? (float) $entry->unit_cost : null,
            'amount' => (float) $entry->amount,
            'incurredOn' => $entry->incurred_on->toDateString(),
            'recordedBy' => $entry->recorder?->name,
        ]);

        $estimate = $job->estimates->first();
        $estimatedItems = $estimate
            ? $estimate->items()->whereIn('category', ['material', 'fixture', 'equipment'])->get()->map(fn ($item) => [
                'category' => $item->category,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'cost' => $canViewCosts ? (float) $item->total : null,
            ])->values()
            : collect();

        if (! $canViewCosts) {
            $summary = JobCostSummary::redact($summary);
            $laborRows = $laborRows->map(fn (array $row) => [...$row, 'laborRate' => null, 'laborCost' => null]);
            $costEntries = $costEntries->map(fn (array $entry) => [...$entry, 'unitCost' => null, 'amount' => null]);
        }

        $hasSchedule = $job->schedule !== null;

        return [
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'status' => $job->status,
                'isLocked' => $job->isLocked(),
            ],
            'range' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
            'canViewCosts' => $canViewCosts,
            'summary' => $summary,
            'laborRows' => $laborRows,
            'costEntries' => $costEntries,
            'estimatedItems' => $estimatedItems,
            'schedule' => [
                'hasSchedule' => $hasSchedule,
                'scheduledHours' => $hasSchedule ? $summary['estimatedLaborHours'] : null,
                'remainingHours' => round(max(0, $summary['estimatedLaborHours'] - $summary['actualLaborHours']), 2),
            ],
            'can' => app(JobCostingPolicy::class)->abilities($user),
            'scope' => app(JobScopeHistory::class)->payload($job, $canViewCosts, limit: 30),
        ];
    }

    /** @return array{date_from: ?string, date_to: ?string, job: ?int, client: string, status: string, cost_type: string, team_member: ?int, tab: string} */
    public function filters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'job' => ['nullable', 'integer'],
            'client' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['all', ...Job::STATUSES])],
            'cost_type' => ['nullable', Rule::in(['all', 'labor', 'material', 'equipment', 'other', 'total'])],
            'team_member' => ['nullable', 'integer'],
            'tab' => ['nullable', Rule::in(self::TABS)],
        ]);

        return [
            // No range means all-time: revenue and estimates are whole-job figures, so a
            // default window would net a few weeks of cost against the full contract value.
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'job' => $data['job'] ?? null,
            'client' => $data['client'] ?? 'all',
            'status' => $data['status'] ?? 'all',
            'cost_type' => $data['cost_type'] ?? 'all',
            'team_member' => $data['team_member'] ?? null,
            'tab' => $data['tab'] ?? 'overview',
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function filteredJobs(array $filters): Collection
    {
        return Job::query()
            ->active()
            ->when($filters['job'], fn ($q) => $q->where('id', $filters['job']))
            ->when($filters['client'] !== 'all', fn ($q) => $q->where('client', $filters['client']))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['team_member'], fn ($q) => $q->whereHas(
                'timeEntries',
                fn ($t) => $t->where('team_member_id', $filters['team_member'])
            ))
            ->get();
    }

    /** @param  array<string, mixed>  $filters */
    public function rowsFor(Collection $jobs, array $filters): Collection
    {
        return $this->costSummary->forMany($jobs, $filters['date_from'], $filters['date_to'], $filters['team_member']);
    }

    /**
     * Actual hours and cost for the window just before the selected one (same
     * length), so the KPI row can say "+12% vs previous period". Only labor
     * and total cost are compared: revenue and estimates are whole-job
     * figures that do not move with a date window.
     *
     * @param  array<string, mixed>  $filters
     * @return array{from: string, to: string, laborHours: float, laborCost: float, totalCost: float}|null
     */
    private function comparison(Collection $jobs, array $filters): ?array
    {
        if ($filters['date_from'] === null) {
            return null;
        }

        $from = \Carbon\Carbon::parse($filters['date_from'])->startOfDay();
        $to = \Carbon\Carbon::parse($filters['date_to'] ?? now()->toDateString())->startOfDay();
        if ($to->lt($from)) {
            return null;
        }

        $days = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $rows = $this->costSummary->forMany($jobs, $prevFrom->toDateString(), $prevTo->toDateString(), $filters['team_member']);

        return [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'laborHours' => round($rows->sum('actualLaborHours'), 2),
            'laborCost' => round($rows->sum('actualLaborCost'), 2),
            'totalCost' => round($rows->sum('actualTotalCost'), 2),
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function matchesCostType(array $row, string $costType): bool
    {
        return match ($costType) {
            'labor' => $row['actualLaborCost'] > $row['estimatedLaborCost'] || $row['actualLaborHours'] > $row['estimatedLaborHours'],
            'material' => $row['actualMaterialCost'] > $row['estimatedMaterialCost'],
            'equipment' => $row['actualEquipmentCost'] > $row['estimatedEquipmentCost'],
            'other' => $row['actualOtherCost'] > $row['estimatedOtherCost'],
            'total' => $row['actualTotalCost'] > $row['estimatedTotalCost'],
            default => $row['isOverBudget'],
        };
    }
}
