<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimerSession;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\TimeTracking\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jobs, as the mobile app sees them: only the ones the signed-in electrician
 * is actually staffed on (see `ElectricianJobAccess`) — a stricter scope
 * than the web app's own Job Detail page, which has no per-job restriction
 * at all. Never a budget/cost figure — those stay exclusively in the
 * manager-facing, `viewJobCosts`-gated web screens.
 */
class JobController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly TimerService $timer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $jobs = $this->access->assignedJobsQuery($request->user())
            ->with('foreman:id,name,initials,role')
            ->withSum('timeEntries', 'hours')
            ->orderBy('start_date')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'jobs' => $jobs->getCollection()->map(fn (Job $job) => $this->summarize($job))->all(),
            'meta' => [
                'currentPage' => $jobs->currentPage(),
                'lastPage' => $jobs->lastPage(),
                'perPage' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    public function show(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $job->load(['foreman:id,name,initials,role,user_id', 'activeAssignments']);

        return $this->ok([
            ...$this->summarize($job),
            'description' => $job->description,
            'assignments' => $job->activeAssignments->map(fn ($a) => [
                'role' => $a->role,
                'name' => $a->name,
            ])->all(),
            'openTasksCount' => $job->tasks()->open()->count(),
            'crewTime' => $this->crewTime($job),
            'myTasksComplete' => $this->myTasksComplete($request, $job),
        ]);
    }

    /**
     * Whether the requesting foreman's own tasks on this job are all done —
     * null for anyone the concept doesn't apply to (a supervisor oversees
     * every task, not a personal slice; an electrician has no crew-register
     * row at all). This is what the app uses to decide when "Mark Job
     * Complete" is even worth a foreman tapping: their own part being done
     * does not mean the *job* is done — `changeStatus()` only actually
     * completes it once every task, from every foreman, is closed.
     */
    private function myTasksComplete(Request $request, Job $job): ?bool
    {
        $foreman = $request->user()->foreman;
        if ($foreman === null || $foreman->role !== Foreman::ROLE_FOREMAN) {
            return null;
        }

        $myTasks = $job->tasks()->where('foreman_id', $foreman->id);
        if (! $myTasks->exists()) {
            return null;
        }

        return ! $myTasks->open()->exists();
    }

    /**
     * How much time every foreman on this job has put in — for a supervisor
     * checking in on work they don't personally do (a foreman already sees
     * their own clock via `/timer`). A job is not one foreman's anymore: it
     * is broken into tasks each with their own foreman, so this reads every
     * distinct one across the job's tasks, not just `job.foreman` (the
     * single header field, which can even be a supervisor's own register
     * row — see `Foreman`'s own doc comment — so it is deliberately not the
     * source here).
     *
     * `totalSeconds` is everything already logged (finalized `TimeEntry`
     * rows) — never ticks. `startedAt`/`accumulatedSeconds` are the active
     * session's own raw fields (null when there is no active session),
     * meant for a client to tick live off `startedAt` the same way the
     * crew's own timer screen does, added on top of `totalSeconds` rather
     * than duplicated into it. `liveElapsedSeconds` is a ready-made,
     * non-ticking snapshot of that same session at fetch time, for a caller
     * that just wants one number right now.
     *
     * @return list<array<string, mixed>>
     */
    private function crewTime(Job $job): array
    {
        // Deduped in PHP, not `->distinct()`: `Job::tasks()` orders by
        // `position, id`, and MySQL refuses `DISTINCT` alongside an `ORDER
        // BY` column that isn't in the selected column itself.
        $foremanIds = $job->tasks()->whereNotNull('foreman_id')->pluck('foreman_id')->unique();
        if ($foremanIds->isEmpty()) {
            return [];
        }

        $foremen = Foreman::whereKey($foremanIds)
            ->where('role', Foreman::ROLE_FOREMAN)
            ->whereNotNull('user_id')
            ->orderBy('name')
            ->get();

        return $foremen->map(function (Foreman $foreman) use ($job) {
            $totalSeconds = (int) round(
                (float) $job->timeEntries()->where('user_id', $foreman->user_id)->sum('hours') * 3600
            );

            $session = TimerSession::where('user_id', $foreman->user_id)->where('job_id', $job->id)->first();

            return [
                'foremanId' => $foreman->id,
                'foremanName' => $foreman->name,
                'totalSeconds' => $totalSeconds,
                'status' => $session?->status,
                'startedAt' => $session?->started_at?->toISOString(),
                'accumulatedSeconds' => $session?->accumulated_seconds,
                'liveElapsedSeconds' => $session ? $this->timer->elapsedSeconds($session) : null,
            ];
        })->values()->all();
    }

    public function changeStatus(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $request->validate([
            'status' => ['required', Rule::in(Job::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($job->isLocked()) {
            return $this->fail('This job is already completed and can no longer be changed.', 409);
        }

        if ($data['status'] === 'in-progress') {
            // Starting a job is the foreman's own call, not a supervisor's —
            // a supervisor oversees the job rather than running it day to
            // day, so their account never gets to be the one that puts a
            // job on the clock. `Foreman::role` (the crew register), not
            // `User::role`, is the authority here — the same distinction
            // `ElectricianJobAccess`/`JobSchedulePolicy` already draw.
            if ($request->user()->foreman?->role === Foreman::ROLE_SUPERVISOR) {
                return $this->fail('Only a foreman can start this job.', 403);
            }

            // Starting a job is only ever "today or already past due" — a job
            // scheduled for next week has no business being marked
            // in-progress this morning just because someone tapped the
            // button.
            if (! $job->hasStarted()
                && $job->start_date !== null
                && $job->start_date->isFuture()) {
                return $this->fail(
                    "This job isn't scheduled to start until {$job->start_date->toFormattedDateString()}.",
                    422,
                );
            }
        }

        if ($data['status'] === 'completed') {
            // The same `Foreman::role` distinction the `in-progress` branch
            // above already draws: only a supervisor's account can actually
            // close a job out. Everyone else completing every task on it
            // only ever gets it as far as ready-for-review.
            $isSupervisor = $request->user()->foreman?->role === Foreman::ROLE_SUPERVISOR;

            if (! $isSupervisor) {
                if ($blocker = $this->prepareCompletion($request, $job, $data['reason'] ?? null)) {
                    return $blocker;
                }

                $job->markReadyForReview();

                return $this->ok([
                    'jobId' => $job->id,
                    'from' => $job->status,
                    'to' => $job->status,
                    'readyForReviewAt' => $job->ready_for_review_at?->toISOString(),
                ], 'Marked ready for supervisor review.');
            }

            if (! $job->isReadyForReview()) {
                return $this->fail(
                    'Waiting for the crew to finish their tasks first.',
                    422,
                    ['code' => 'not_ready_for_review'],
                );
            }
        }

        $from = $job->status;

        // `Job::changeStatus()` is the exact same model method
        // `JobController::changeStatus()` (web) calls — it fires
        // `JobStatusChanged` itself, so the broadcast reaches Phase 8's
        // `job.{id}` channel identically regardless of which client caused it.
        $job->changeStatus($data['status']);

        return $this->ok([
            'jobId' => $job->id,
            'from' => $from,
            'to' => $job->status,
        ], $from === $job->status ? 'Status unchanged.' : 'Status updated.');
    }

    /**
     * Finalizes a job's worked time before it's allowed to complete.
     *
     * A job is not one foreman's to close: it only actually completes once
     * every task on it — from every foreman assigned, not just whoever
     * tapped this — is itself done. A foreman finishing their own slice
     * gets `myTasksComplete` (see `show()`) to know they're personally
     * done; the job as a whole stays open until the last one closes theirs.
     *
     * Once that's true, if the technician still has a clock running on this
     * job, it's stopped first so its hours count — a foreman who taps "Mark
     * Complete" without remembering to stop the clock shouldn't lose that
     * time. The total — every foreman's logged hours on the job combined —
     * is then compared against `Job::estimated_hours`: if it ran over, a
     * reason is required (the same "why the extra time" the web app will
     * show later) before the job is allowed to close. All three numbers —
     * worked total, delay, reason — are saved on the job itself so the web
     * app has them.
     *
     * Returns a 422 response if completion should be blocked, or null to
     * let `changeStatus()` proceed.
     */
    private function prepareCompletion(Request $request, Job $job, ?string $reason): ?JsonResponse
    {
        $openTasks = $job->tasks()->open()->count();
        if ($openTasks > 0) {
            return $this->fail(
                "This job isn't done yet — {$openTasks} open ".
                str('task')->plural($openTasks)." across the crew still need to be completed.",
                422,
                ['code' => 'tasks_incomplete', 'openTasksCount' => $openTasks],
            );
        }

        $active = $this->timer->active($request->user());
        if ($active !== null && $active->job_id === $job->id) {
            $this->timer->stop($active);
        }

        $actualHours = round((float) $job->timeEntries()->sum('hours'), 2);
        $estimatedHours = (float) ($job->estimated_hours ?? 0);
        $delayHours = round(max(0, $actualHours - $estimatedHours), 2);

        if ($delayHours > 0 && trim((string) $reason) === '') {
            return $this->fail(
                "This job took {$delayHours}h longer than the {$estimatedHours}h estimated. Add a reason before marking it complete.",
                422,
                ['code' => 'delay_reason_required', 'delayHours' => $delayHours, 'estimatedHours' => $estimatedHours, 'actualHours' => $actualHours],
            );
        }

        $job->actual_hours = $actualHours;
        $job->delay_hours = $delayHours;
        $job->delay_reason = $delayHours > 0 ? $reason : null;
        $job->save();

        return null;
    }

    /** @return array<string, mixed> */
    private function summarize(Job $job): array
    {
        return [
            'id' => $job->id,
            'name' => $job->name,
            'client' => $job->client,
            'location' => $job->location,
            /*
             * The site's point, for the crew app: GPS check-in, geofencing and
             * on-site status all measure against this. Null on a job whose
             * address was typed rather than chosen, which the app has to treat
             * as "no geofence" rather than as the origin.
             */
            'latitude' => $job->latitude === null ? null : (float) $job->latitude,
            'longitude' => $job->longitude === null ? null : (float) $job->longitude,
            'placeId' => $job->place_id,
            'jobType' => $job->job_type,
            'status' => $job->status,
            'priority' => $job->priority,
            'startDate' => $job->start_date?->toDateString(),
            'endDate' => $job->end_date?->toDateString(),
            // The crew's clock reads against these: `estimatedHours` is the
            // budget from the task setup wizard; `workedHoursSoFar` is
            // everything already logged (timer-stopped or manual) excluding
            // whatever clock is running right now, which the app adds itself
            // from its own live ticker. `actualHours`/`delayHours`/
            // `delayReason` stay null until the job is actually completed —
            // `prepareCompletion()` is what fills them in.
            'estimatedHours' => $job->estimated_hours !== null ? (float) $job->estimated_hours : null,
            // `index()` eager-loads this sum (`withSum`) to avoid an N+1 across
            // a whole page of jobs; `show()` has only one job to ask, so the
            // live relation query there is cheap and needs no such preload.
            'workedHoursSoFar' => round(
                (float) ($job->time_entries_sum_hours ?? $job->timeEntries()->sum('hours')),
                2,
            ),
            'actualHours' => $job->actual_hours !== null ? (float) $job->actual_hours : null,
            'delayHours' => $job->delay_hours !== null ? (float) $job->delay_hours : null,
            'delayReason' => $job->delay_reason,
            // Set once the crew has closed every task and tapped Complete —
            // null again the moment a supervisor reopens one. Only a
            // supervisor's own tap while this is set actually finishes the
            // job (`changeStatus()`).
            'readyForReviewAt' => $job->ready_for_review_at?->toISOString(),
            'foreman' => $job->foreman ? [
                'name' => $job->foreman->name,
                'initials' => $job->foreman->initials,
                // "Foreman" or "Supervisor" — the register row this points
                // to isn't always a foreman despite the relation's name (see
                // `Foreman`'s own doc comment), so the app must not hardcode
                // the label either.
                'role' => $job->foreman->roleLabel(),
            ] : null,
        ];
    }
}
