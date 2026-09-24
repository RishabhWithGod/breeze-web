<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobTaskResource;
use App\Models\CrewShift;
use App\Models\Job;
use App\Models\JobTask;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Scheduling\JobTaskWorkflowService;
use App\Services\TimeTracking\TeamMemberResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A job's schedule, read-only, as the mobile app needs it — the plan's
 * window/status plus this person's own tasks, not the web Schedule
 * screen's full timeline/calendar/dependency-graph payload (which is sized
 * for a desktop screen and a click-through UI, not a mobile data budget).
 *
 * No mutation here: schedule planning (dates, working week, staffing,
 * dependencies) stays a web/office action. The one schedule-affecting
 * thing mobile does — completing a task — goes through
 * `JobTaskController::complete()` in this same API, which already
 * broadcasts `ScheduleChanged`.
 */
class ScheduleController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly TeamMemberResolver $resolver,
        private readonly JobTaskWorkflowService $workflow,
    ) {}

    /**
     * "My Schedule" — every upcoming crew shift across every job this user
     * can access (`ElectricianJobAccess::assignedJobsQuery`), the same
     * `CrewShift` rows and `scheduled_date`/`start_time` ordering the web
     * Scheduling calendar (`SchedulingController::calendar`) uses, just
     * flattened into a paginated list instead of a bounded week/month grid.
     *
     * Soonest-first (ascending), not newest-first: this is a forward
     * calendar of what's coming up, not a log of what changed — reversing
     * it would show the most distant future shift first, which is exactly
     * the wrong order for a schedule. Matches web's own ordering exactly.
     */
    public function index(Request $request): JsonResponse
    {
        $shifts = CrewShift::query()
            ->whereIn('job_id', $this->access->assignedJobsQuery($request->user())->select('id'))
            ->with(['job:id,name,client,location', 'teamMember:id,name,initials,role'])
            ->where('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'shifts' => $shifts->getCollection()->map(fn (CrewShift $shift) => [
                'id' => $shift->id,
                'jobId' => $shift->job_id,
                'jobName' => $shift->job?->name ?? '',
                'address' => $shift->job?->location ?? '',
                'crewName' => $shift->crew ?: ($shift->teamMember?->name ?? ''),
                'scheduledDate' => $shift->scheduled_date->toDateString(),
                'startTime' => $shift->start_time,
                'durationHours' => (float) $shift->duration_hours,
                'status' => $shift->status,
            ])->all(),
            'meta' => [
                'currentPage' => $shifts->currentPage(),
                'lastPage' => $shifts->lastPage(),
                'perPage' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    public function show(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $schedule = $job->schedule;

        if ($schedule === null) {
            return $this->ok(['schedule' => null, 'myTasks' => []], 'No schedule has been created for this job yet.');
        }

        $teamMemberId = $this->resolver->resolveFor($request->user())->id;
        $foremanId = $request->user()->foreman?->id;

        $myTasks = $job->tasks()
            ->where(function ($query) use ($teamMemberId, $foremanId) {
                $query->whereHas('members', fn ($q) => $q->where('team_members.id', $teamMemberId));

                // Named as the task's foreman/supervisor — the same signal
                // `ElectricianJobAccess` treats as real staffing, so a
                // technician assigned this way sees their own tasks here too,
                // not just ones reached through the full scheduling system.
                if ($foremanId !== null) {
                    $query->orWhere(fn ($q) => $q->heldBy($foremanId));
                }
            })
            ->with(['assignments.member', 'estimateItems'])
            ->orderBy('starts_on')
            ->get();

        $myTasks->each(fn (JobTask $task) => $this->workflow->reconcileChecklistProgress($task));

        return $this->ok([
            'schedule' => [
                'id' => $schedule->id,
                'startsOn' => $schedule->starts_on?->toDateString(),
                'endsOn' => $schedule->ends_on?->toDateString(),
                'status' => $schedule->status,
                'progressPct' => $schedule->progress_pct,
                'timezone' => $schedule->timezone,
                'workStartTime' => substr((string) $schedule->work_start_time, 0, 5),
                'workEndTime' => substr((string) $schedule->work_end_time, 0, 5),
            ],
            'myTasks' => JobTaskResource::collection($myTasks)->resolve($request),
        ]);
    }
}
