<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\JobAttendance;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\TimeEntryPolicy;
use App\Services\TimeTracking\DailyTimesheetBuilder;
use App\Services\TimeTracking\TimesheetWeekBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The Time Log Viewer — mobile's counterpart to web's own
 * `TimeEntryController::index()`/`showDay()`: one row per technician per
 * calendar day (`DailyTimesheetBuilder`), not one row per `TimeEntry`. A
 * technician who stopped their timer four times, or checked in/out of two
 * jobs, still worked one day — the same grouping web's "Time Log Viewer"
 * table shows, including GPS `JobAttendance` sessions alongside timer/manual
 * ones (`hasTimerEntries`/`hasAttendance`/`attendanceStatus`). Also the
 * personal weekly timesheet grid (`TimesheetWeekBuilder`) — same builder as
 * web's own `TimeTrackingController::week()`.
 */
class TimeTrackingController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TimesheetWeekBuilder $weekBuilder,
        private readonly DailyTimesheetBuilder $dailyBuilder,
    ) {}

    public function week(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);

        $anchor = isset($data['date']) ? Carbon::parse($data['date']) : now();
        $week = $this->weekBuilder->build($request->user(), $anchor);

        return $this->ok([
            'week' => $week,
            'prevWeekDate' => $anchor->copy()->subWeek()->toDateString(),
            'nextWeekDate' => $anchor->copy()->addWeek()->toDateString(),
            'thisWeekDate' => now()->toDateString(),
        ]);
    }

    public function days(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewCrew = (bool) $user->can('viewCrew', TimeEntry::class);

        $raw = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'job_id' => ['nullable', 'integer'],
            'team_member_id' => ['nullable', 'integer'],
            'task_type' => ['nullable', Rule::in(JobTask::CATEGORIES)],
            'status' => ['nullable', Rule::in(TimeEntry::STATUSES)],
            'billable' => ['nullable', Rule::in(['yes', 'no'])],
        ]);
        // `DailyTimesheetBuilder` expects the same keys
        // `TimeEntryController::parseFilters()` (web) validates.
        $filters = [
            'search' => $raw['search'] ?? '',
            'from' => $raw['from'] ?? '',
            'to' => $raw['to'] ?? '',
            'job' => $raw['job_id'] ?? '',
            'team_member' => $raw['team_member_id'] ?? '',
            'task_type' => $raw['task_type'] ?? 'all',
            'status' => $raw['status'] ?? 'all',
            'billable' => $raw['billable'] ?? 'all',
        ];

        $days = $this->dailyBuilder->paginate(
            $filters,
            $canViewCrew,
            $user->id,
            min((int) $request->integer('per_page', 20), 50),
            (int) $request->integer('page', 1),
        );

        return $this->ok([
            'days' => $days->items(),
            'meta' => [
                'currentPage' => $days->currentPage(),
                'lastPage' => $days->lastPage(),
                'total' => $days->total(),
            ],
            'can' => app(TimeEntryPolicy::class)->abilities($user),
        ]);
    }

    /**
     * One technician's one day, in full — every timer/manual session and
     * every GPS check-in cycle behind the day row's single total. Mirrors
     * web's `TimeEntryController::showDay()` exactly, including
     * `aggregateStatus()`/`aggregateAttendanceStatus()` so the list and this
     * screen never disagree about what a day's status is.
     */
    public function day(Request $request, User $user, string $date): JsonResponse
    {
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);
        abort_unless($canViewCrew || $user->id === $request->user()->id, 403);

        $day = Carbon::parse($date);

        $entries = TimeEntry::query()
            ->with([
                'job:id,name', 'jobTask:id,title', 'teamMember:id,name,role',
                'user:id,name,role,initials', 'approver:id,name', 'rejecter:id,name',
            ])
            ->where('user_id', $user->id)
            ->whereDate('date', $day)
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        $attendance = JobAttendance::query()
            ->with('job:id,name')
            ->where('user_id', $user->id)
            ->whereDate('date', $day)
            ->orderBy('check_in_at')
            ->get();

        abort_if($entries->isEmpty() && $attendance->isEmpty(), 404);

        $jobBreakdown = $entries->map(fn (TimeEntry $e) => [$e->job?->name ?? 'No job', (float) $e->hours])
            ->concat($attendance->map(fn (JobAttendance $a) => [$a->job?->name ?? 'No job', $a->workingSeconds() / 3600]))
            ->groupBy(fn ($pair) => $pair[0])
            ->map(fn ($pairs, $job) => ['job' => $job, 'hours' => round((float) $pairs->sum(fn ($p) => $p[1]), 2)])
            ->values();

        return $this->ok([
            'userId' => $user->id,
            'date' => $day->toDateString(),
            'employee' => [
                'name' => $entries->first()?->teamMember?->name ?? $user->name,
                'role' => $user->role,
            ],
            'totalHours' => round(
                (float) $entries->sum('hours')
                    + $attendance->sum(fn (JobAttendance $a) => $a->workingSeconds()) / 3600,
                2
            ),
            'status' => $this->dailyBuilder->aggregateStatus($entries),
            'attendanceStatus' => $this->dailyBuilder->aggregateAttendanceStatus($attendance),
            'jobBreakdown' => $jobBreakdown,
            'entries' => TimeEntryResource::collection($entries)->resolve($request),
            'attendance' => $attendance->map(fn (JobAttendance $row) => [
                'id' => $row->id,
                'date' => $row->date->toDateString(),
                'employee' => $entries->first()?->teamMember?->name ?? $user->name,
                'employeeRole' => $user->role,
                'job' => $row->job ? ['id' => $row->job->id, 'name' => $row->job->name] : null,
                'status' => $row->status,
                'checkInAt' => $row->check_in_at?->toISOString(),
                'checkOutAt' => $row->check_out_at?->toISOString(),
                'checkInMethod' => $row->check_in_method,
                'checkOutMethod' => $row->check_out_method,
                'checkInDistanceMeters' => $row->check_in_distance_meters !== null
                    ? (float) $row->check_in_distance_meters
                    : null,
                'checkOutDistanceMeters' => $row->check_out_distance_meters !== null
                    ? (float) $row->check_out_distance_meters
                    : null,
                'hours' => round($row->workingSeconds() / 3600, 2),
                'hasPhoto' => $row->check_in_photo_path !== null,
            ])->all(),
        ]);
    }
}
