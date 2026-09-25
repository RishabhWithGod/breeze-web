<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Services\TimeTracking\JobLaborSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reports over approved time, mobile's counterpart to web's
 * `TimeTrackingReportController::index()` — same `viewReports` policy
 * (project manager/admin/owner only), same approved-rows-only rule
 * `JobLaborSummary` and `TaskActualHoursRecalculator` hold to elsewhere.
 * CSV/XLSX export stays web-only — a file-save/share flow is a different
 * problem from the numbers themselves, which this endpoint sends in full.
 */
class TimeTrackingReportController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly JobLaborSummary $laborSummary) {}

    public function index(Request $request): JsonResponse
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

        return $this->ok([
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

    private function approvedQuery(Carbon $from, Carbon $to): Builder
    {
        return TimeEntry::query()
            ->whereBetween('time_entries.date', [$from->toDateString(), $to->toDateString()])
            ->where('time_entries.status', TimeEntry::STATUS_APPROVED);
    }
}
