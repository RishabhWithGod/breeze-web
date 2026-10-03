<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobTaskResource;
use App\Models\CrewShift;
use App\Models\CrewShiftAcknowledgement;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Models\JobTask;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Scheduling\JobTaskWorkflowService;
use App\Services\TimeTracking\TeamMemberResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        $user = $request->user();
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $ranged = isset($range['from']);
        $teamMemberId = $this->resolver->resolveFor($user)->id;

        // What this person works: the jobs they are staffed on (a manager's is every job), and any
        // shift booked to them by name.
        $shifts = CrewShift::query()
            ->where(fn ($query) => $query
                ->whereIn('job_id', $this->access->listedJobsQuery($user)->select('id'))
                ->orWhere('team_member_id', $teamMemberId))
            ->with(['job:id,name,client,location', 'teamMember:id,name,initials,role'])
            ->when(
                $ranged,
                fn ($q) => $q->whereBetween('scheduled_date', [$range['from'], $range['to'] ?? $range['from']]),
                fn ($q) => $q->where('scheduled_date', '>=', now()->toDateString()),
            )
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate(min((int) $request->integer('per_page', $ranged ? 200 : 20), 200));

        $acknowledged = CrewShiftAcknowledgement::query()
            ->where('user_id', $user->id)
            ->whereIn('crew_shift_id', $shifts->getCollection()->pluck('id'))
            ->get()
            ->mapWithKeys(fn (CrewShiftAcknowledgement $ack) => [$ack->crew_shift_id => $ack->acknowledged_at]);

        $rows = $shifts->getCollection()->map(fn (CrewShift $shift) => $this->present($shift, $acknowledged[$shift->id] ?? null));

        // A job someone is assigned to belongs on their schedule even before
        // the office has booked crew shifts for it.
        $planned = $this->plannedDays(
            $user,
            $teamMemberId,
            $ranged ? Carbon::parse($range['from'])->startOfDay() : now()->startOfDay(),
            $ranged ? Carbon::parse($range['to'] ?? $range['from'])->startOfDay() : now()->startOfDay()->addDays(60),
            $ranged || $shifts->currentPage() === 1,
        );
        $rows = $rows->concat($planned)
            ->sortBy([['scheduledDate', 'asc'], ['startTime', 'asc'], ['id', 'desc']])
            ->values();

        return $this->ok([
            // When this list was read — what the app shows as "last updated" and keeps for offline.
            'generatedAt' => now()->toISOString(),
            'shifts' => $rows->all(),
            'meta' => [
                'currentPage' => $shifts->currentPage(),
                'lastPage' => $shifts->lastPage(),
                'perPage' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    /**
     * Working days, in `[$from, $to]`, of every job this person is assigned
     * to that has no crew shifts booked at all. Read live from the job's own
     * dates, so reassigning someone or moving a date changes their schedule
     * with no extra bookkeeping; the moment the office books real shifts for
     * the job, those take over and these stop appearing.
     *
     * The span is the person's own tasks' dates where they have any, else the
     * job's own start/end. The id is negative and derived from job + day: it
     * is stable between reads, cannot collide with a real shift's id, and is
     * never acknowledged (`changed` is always false).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function plannedDays(\App\Models\User $user, int $teamMemberId, Carbon $from, Carbon $to, bool $include): \Illuminate\Support\Collection
    {
        if (! $include) {
            return collect();
        }

        $foremanId = $user->foreman?->id;
        $jobs = $this->access->staffedJobs($user)
            ->where('status', '!=', Job::STATUS_COMPLETED)
            ->whereNotIn('id', CrewShift::query()->select('job_id'))
            ->with(['schedule', 'team:id,name'])
            ->get();

        if ($jobs->isEmpty()) {
            return collect();
        }

        $taskSpans = JobTask::query()
            ->whereIn('job_id', $jobs->pluck('id'))
            ->where(function ($query) use ($teamMemberId, $foremanId) {
                $query->whereHas('members', fn ($q) => $q->where('team_members.id', $teamMemberId));
                if ($foremanId !== null) {
                    $query->orWhere(fn ($q) => $q->heldBy($foremanId));
                }
            })
            ->whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->selectRaw('job_id, min(starts_on) as first_day, max(ends_on) as last_day')
            ->groupBy('job_id')
            ->reorder()
            ->get()
            ->keyBy('job_id');

        $days = collect();

        foreach ($jobs as $job) {
            $span = $taskSpans->get($job->id);
            $start = $span?->first_day ?? $job->start_date;
            $end = $span?->last_day ?? $job->end_date ?? $start;

            if ($start === null) {
                continue;
            }

            $start = Carbon::parse($start)->startOfDay();
            $end = Carbon::parse($end)->startOfDay();
            $schedule = $job->schedule;
            $startTime = $schedule?->work_start_time ?: '08:00:00';
            $hours = $schedule !== null ? max(0.5, min(24, $schedule->hoursPerDay())) : 8.0;

            for ($day = $start->copy()->max($from); $day->lte($end->copy()->min($to)); $day->addDay()) {
                if ($schedule !== null ? ! $schedule->isWorkingDay($day) : $day->isWeekend()) {
                    continue;
                }

                $days->push([
                    'id' => -($job->id * 100000 + (int) Carbon::create(2020, 1, 1)->diffInDays($day)),
                    'jobId' => $job->id,
                    'jobName' => $job->name ?? '',
                    'client' => $job->client,
                    'address' => $job->location ?? '',
                    'crewName' => $job->team?->name ?? $user->name,
                    'scheduledDate' => $day->toDateString(),
                    'startTime' => $startTime,
                    'endTime' => Carbon::parse($startTime)->addMinutes((int) round($hours * 60))->format('H:i:s'),
                    'durationHours' => $hours,
                    'status' => CrewShift::STATUS_SCHEDULED,
                    'notes' => null,
                    'changed' => false,
                    'changedAt' => null,
                    'acknowledgedAt' => null,
                    'updatedAt' => $job->updated_at?->toISOString(),
                ]);
            }
        }

        return $days;
    }

    /** "Got it" on a changed shift: it stops reading Changed for this person. */
    public function acknowledge(Request $request, CrewShift $shift): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $this->access->canAccess($user, $shift->job) || $shift->team_member_id === $this->resolver->resolveFor($user)->id,
            403,
            'That shift is not on your schedule.',
        );

        $ack = CrewShiftAcknowledgement::updateOrCreate(
            ['crew_shift_id' => $shift->id, 'user_id' => $user->id],
            ['acknowledged_at' => now()],
        );

        return $this->ok($this->present($shift->load(['job:id,name,client,location', 'teamMember:id,name,initials,role']), $ack->acknowledged_at), 'Schedule change acknowledged.');
    }

    /** @return array<string, mixed> */
    private function present(CrewShift $shift, mixed $acknowledgedAt): array
    {
        $changed = $shift->changed_at !== null
            && ($acknowledgedAt === null || $acknowledgedAt->lt($shift->changed_at));

        return [
            'id' => $shift->id,
            'jobId' => $shift->job_id,
            'jobName' => $shift->job?->name ?? '',
            'client' => $shift->job?->client,
            'address' => $shift->job?->location ?? '',
            'crewName' => $shift->crew ?: ($shift->teamMember?->name ?? ''),
            'scheduledDate' => $shift->scheduled_date->toDateString(),
            'startTime' => $shift->start_time,
            'endTime' => Carbon::parse($shift->start_time)->addMinutes((int) round(((float) $shift->duration_hours) * 60))->format('H:i:s'),
            'durationHours' => (float) $shift->duration_hours,
            'status' => $shift->status,
            'notes' => $shift->notes,
            'changed' => $changed,
            'changedAt' => $shift->changed_at?->toISOString(),
            'acknowledgedAt' => $acknowledgedAt?->toISOString(),
            'updatedAt' => $shift->updated_at?->toISOString(),
        ];
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

        // An apprentice reads the work of the journeyman they were put under on this job —
        // to see it, never to change it (every write route stays behind `block.apprentice`).
        $journeymanIds = $request->user()->foreman?->role === Foreman::ROLE_APPRENTICE
            ? JobApprenticeAssignment::query()->where('job_id', $job->id)->where('apprentice_id', $foremanId)->pluck('journeyman_id')
            : collect();

        $myTasks = $job->tasks()
            ->where(function ($query) use ($teamMemberId, $foremanId, $journeymanIds) {
                if ($journeymanIds->isNotEmpty()) {
                    $query->heldByAny($journeymanIds);
                }

                $query->orWhereHas('members', fn ($q) => $q->where('team_members.id', $teamMemberId));

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
            'readOnly' => $request->user()->foreman?->role === Foreman::ROLE_APPRENTICE,
        ]);
    }
}
