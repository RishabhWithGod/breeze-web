<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Export\TimesheetExporter;
use App\Services\TimeTracking\JobLaborSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports over approved time: by job, by employee, by task, billable split,
 * overtime, labor cost, and estimated-vs-actual — all for a date range.
 *
 * Only `approved` rows are counted, the same rule `JobLaborSummary` and
 * `TaskActualHoursRecalculator` use — a report can never show hours that
 * have not actually been signed off.
 */
class TimeTrackingReportController extends Controller
{
    public function __construct(
        private readonly JobLaborSummary $laborSummary,
        private readonly TimesheetExporter $exporter,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewReports', TimeEntry::class);

        [$from, $to] = $this->range($request);
        $base = fn () => $this->approvedQuery($from, $to);

        $byJob = $base()
            ->join('work_jobs', 'work_jobs.id', '=', 'time_entries.job_id')
            ->selectRaw('work_jobs.id as id, work_jobs.name as label, sum(time_entries.hours) as hours, sum(time_entries.labor_cost) as cost')
            ->groupBy('work_jobs.id', 'work_jobs.name')
            ->orderByDesc('hours')
            ->get();

        $byEmployee = $base()
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->selectRaw('users.id as id, users.name as label, sum(time_entries.hours) as hours, sum(time_entries.overtime_hours) as overtime, sum(time_entries.labor_cost) as cost')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('hours')
            ->get();

        $byTask = $base()
            ->whereNotNull('job_task_id')
            ->join('job_tasks', 'job_tasks.id', '=', 'time_entries.job_task_id')
            ->selectRaw('job_tasks.id as id, job_tasks.title as label, sum(time_entries.hours) as hours')
            ->groupBy('job_tasks.id', 'job_tasks.title')
            ->orderByDesc('hours')
            ->get();

        $canViewCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);

        $estimatedVsActual = Job::query()
            ->whereHas('timeEntries', fn ($query) => $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->get()
            ->map(fn (Job $job) => ['job' => $job->name, 'jobId' => $job->id, ...$this->laborSummary->for($job)])
            ->values();

        return Inertia::render('TimeTrackingReports', [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'byJob' => $byJob,
            'byEmployee' => $byEmployee,
            'byTask' => $byTask,
            'billableSplit' => [
                'billable' => (float) $base()->where('billable', true)->sum('hours'),
                'nonBillable' => (float) $base()->where('billable', false)->sum('hours'),
            ],
            'overtimeTotal' => round((float) $base()->sum('overtime_hours'), 2),
            'laborCostTotal' => $canViewCosts ? round((float) $base()->sum('labor_cost'), 2) : null,
            'estimatedVsActual' => $estimatedVsActual,
            'canViewCosts' => $canViewCosts,
        ]);
    }

    public function export(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        $this->authorize('viewReports', TimeEntry::class);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        [$from, $to] = $this->range($request);

        $entries = TimeEntry::with(['job', 'jobTask', 'user', 'teamMember'])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->orderBy('date')
            ->get();

        $filename = "time-entries-{$from->toDateString()}-to-{$to->toDateString()}";

        if ($format === 'csv') {
            $contents = $this->exporter->csv($entries);

            return ResponseFactory::streamDownload(
                fn () => print $contents,
                "{$filename}.csv",
                ['Content-Type' => 'text/csv'],
            );
        }

        return ResponseFactory::download($this->exporter->xlsx($entries), "{$filename}.xlsx")
            ->deleteFileAfterSend();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return [
            isset($data['from']) ? Carbon::parse($data['from']) : now()->startOfMonth(),
            isset($data['to']) ? Carbon::parse($data['to']) : now(),
        ];
    }

    /**
     * Columns are qualified with the table name throughout — `byJob`/`byTask`
     * join `work_jobs`/`job_tasks`, which each have their own `status` column,
     * and an unqualified `where('status', ...)` would otherwise be ambiguous
     * the moment a join is added.
     */
    private function approvedQuery(Carbon $from, Carbon $to): Builder
    {
        return TimeEntry::query()
            ->whereBetween('time_entries.date', [$from->toDateString(), $to->toDateString()])
            ->where('time_entries.status', TimeEntry::STATUS_APPROVED);
    }
}
