<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsSetting;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\JobCostingPolicy;
use App\Services\Billing\ActualCostInvoiceSync;
use App\Services\Export\JobCostingExporter;
use App\Services\JobCosting\AnalyticsDashboard;
use App\Services\JobCosting\JobCostOverrunNotifier;
use App\Services\JobCosting\JobCostSummary;
use App\Services\JobScope\JobScopeHistory;
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
        private readonly AnalyticsDashboard $dashboard,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('JobCosting', $this->dashboard->build($request->user(), $this->dashboard->filters($request)));
    }

    public function export(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        // Exporting is still a view of cost figures — same gate as the dashboard.
        abort_unless($request->user()->can('viewJobCosts', TimeEntry::class), 403);

        $filters = $this->dashboard->filters($request);
        $jobs = $this->dashboard->filteredJobs($filters);
        $rows = $this->dashboard->rowsFor($jobs, $filters);

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

        return Inertia::render('JobCostingDetail', $this->dashboard->jobDetail($request->user(), $job, $data));
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

    /** The dashboard's thresholds — a manager's call, since they decide what counts as an alert. */
    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless(app(JobCostingPolicy::class)->manage($request->user()), 403);

        $validated = $request->validate([
            'low_margin_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'hours_warning_pct' => ['required', 'numeric', 'min:1', 'max:100'],
            'stale_timer_hours' => ['required', 'integer', 'min:1', 'max:72'],
            'overdue_grace_days' => ['required', 'integer', 'min:0', 'max:90'],
        ]);

        AnalyticsSetting::current()->update([...$validated, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'Analytics thresholds saved.');
    }

    /** @param  array<string, mixed>  $filters */
    private function rowsFor(Collection $jobs, array $filters): Collection
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

    /** @return array{date_from: ?string, date_to: ?string, job: ?int, client: string, status: string, cost_type: string, team_member: ?int, tab: string} */
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
}
