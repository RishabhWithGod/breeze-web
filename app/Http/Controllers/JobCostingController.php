<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\JobCostingPolicy;
use App\Services\Billing\ActualCostInvoiceSync;
use App\Services\Export\JobCostingExporter;
use App\Services\JobCosting\JobCostOverrunNotifier;
use App\Services\JobCosting\JobCostSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Job Costing dashboard: every active job's estimated-vs-actual, rolled
 * up into totals, alerts, and rankings — all recomputed from
 * `JobCostSummary`, never a second copy of its arithmetic.
 */
class JobCostingController extends Controller
{
    public function __construct(
        private readonly JobCostSummary $costSummary,
        private readonly JobCostingExporter $exporter,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $canViewCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);

        $jobs = $this->filteredJobs($filters);
        $rows = $jobs->map(fn (Job $job) => $this->costSummary->for($job, $filters['date_from'], $filters['date_to']));

        $costType = $filters['cost_type'];
        $overrunRows = $costType === 'all'
            ? $rows->filter(fn (array $r) => $r['isOverBudget'])
            : $rows->filter(fn (array $r) => $this->matchesCostType($r, $costType));

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

        $jobsByStatus = collect(Job::STATUSES)
            ->map(fn (string $status) => ['status' => $status, 'count' => $jobs->where('status', $status)->count()])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values();

        return Inertia::render('JobCosting', [
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
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        // Exporting is still a view of cost figures — same gate as the dashboard.
        abort_unless($request->user()->can('viewJobCosts', TimeEntry::class), 403);

        $filters = $this->filters($request);
        $jobs = $this->filteredJobs($filters);
        $rows = $jobs->map(fn (Job $job) => $this->costSummary->for($job, $filters['date_from'], $filters['date_to']));

        $filename = 'job-costing-'.now()->toDateString();

        if ($format === 'csv') {
            $contents = $this->exporter->csv($rows);

            return ResponseFactory::streamDownload(
                fn () => print $contents,
                "{$filename}.csv",
                ['Content-Type' => 'text/csv'],
            );
        }

        return ResponseFactory::download($this->exporter->xlsx($rows), "{$filename}.xlsx")
            ->deleteFileAfterSend();
    }

    /** The Job Costing detail screen for one job — all-time unless a range is given. */
    public function show(Request $request, Job $job): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $canViewCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);
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

        return Inertia::render('JobCostingDetail', [
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
            'can' => app(JobCostingPolicy::class)->abilities($request->user()),
        ]);
    }

    public function storeCostEntry(Request $request, Job $job, JobCostOverrunNotifier $notifier, ActualCostInvoiceSync $invoiceSync): RedirectResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);
        $job->assertNotLocked();

        $validated = $request->validate([
            'category' => ['required', Rule::in(JobCostEntry::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'incurred_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $job->costEntries()->create([
            ...$validated,
            'recorded_by' => $request->user()->id,
        ]);

        $notifier->checkAndNotify($job);
        $invoiceSync->sync($job);

        return back()->with('success', "\"{$validated['description']}\" recorded.");
    }

    public function destroyCostEntry(Request $request, Job $job, JobCostEntry $entry, ActualCostInvoiceSync $invoiceSync): RedirectResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);
        abort_unless($entry->job_id === $job->id, 404);
        $job->assertNotLocked();

        $description = $entry->description;
        $entry->delete();
        $invoiceSync->sync($job);

        return back()->with('warning', "\"{$description}\" removed.");
    }

    /**
     * A manager's own direct total for one person on this job — the Billing
     * screen's "Journeyman Labor Hours" card. Saved outright, no approval
     * step: once this row exists it is what `JobCostSummary` reports for
     * that person, in place of whatever their raw time entries add up to.
     *
     * Not gated by `assertNotLocked()`: billing is normally raised *after*
     * a job is completed, so refusing this once the job is done would make
     * the one time this card matters most the one time it can't be used.
     */
    public function updateJourneymanHours(Request $request, Job $job, User $user, ActualCostInvoiceSync $invoiceSync): RedirectResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);

        $validated = $request->validate([
            'hours' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        $job->journeymanHours()->updateOrCreate(
            ['user_id' => $user->id],
            ['hours' => $validated['hours'], 'updated_by' => $request->user()->id],
        );

        $invoiceSync->sync($job);

        return back()->with('success', "{$user->name}'s hours were updated.");
    }

    /** @return array{date_from: ?string, date_to: ?string, job: ?int, client: string, status: string, cost_type: string, team_member: ?int} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'job' => ['nullable', 'integer'],
            'client' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['all', ...Job::STATUSES])],
            'cost_type' => ['nullable', Rule::in(['all', 'labor', 'material', 'equipment', 'other', 'total'])],
            'team_member' => ['nullable', 'integer'],
        ]);

        return [
            'date_from' => $data['date_from'] ?? now()->subDays(30)->toDateString(),
            'date_to' => $data['date_to'] ?? now()->toDateString(),
            'job' => $data['job'] ?? null,
            'client' => $data['client'] ?? 'all',
            'status' => $data['status'] ?? 'all',
            'cost_type' => $data['cost_type'] ?? 'all',
            'team_member' => $data['team_member'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function filteredJobs(array $filters): Collection
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
