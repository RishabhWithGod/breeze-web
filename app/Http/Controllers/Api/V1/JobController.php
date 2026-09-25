<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimerSession;
use App\Services\Clients\JobSites;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\TimeTracking\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Jobs, as the mobile app sees them: only the ones the signed-in electrician
 * is actually staffed on (see `ElectricianJobAccess`) — a stricter scope
 * than the web app's own Job Detail page, which has no per-job restriction
 * at all. `show()` sends the raw `budget` figure (same as web's
 * `JobDetailResource`) to a non-apprentice viewer; only the cost
 * *breakdown* stays behind `viewJobCosts` on web, and this endpoint never
 * builds one at all.
 */
class JobController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly TimerService $timer,
        private readonly JobSites $sites,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $jobs = $this->access->assignedJobsQuery($request->user())
            ->with(['foreman:id,name,initials,role', 'addresses:id'])
            ->withSum('timeEntries', 'hours')
            // Newest first — the mobile list is a feed of what's current,
            // not a schedule to work through chronologically.
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'jobs' => $jobs->getCollection()->map(fn (Job $job) => $this->summarize($job, $request))->all(),
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

        $job->load(['foreman:id,name,initials,role,user_id', 'activeAssignments', 'addresses:id']);

        // An apprentice gets basic info only — no crew roster, task counts,
        // crew time, or review state. They cannot reach the task/material
        // endpoints that data describes anyway (see `BlockApprenticeAccess`),
        // so the job-show response shouldn't describe it to them either.
        if ($this->isApprentice($request)) {
            return $this->ok([
                ...$this->summarize($job, $request),
                'description' => $job->description,
            ]);
        }

        return $this->ok([
            ...$this->summarize($job, $request),
            'description' => $job->description,
            // Unlike the list/apprentice view, a foreman/journeyman's own Job
            // Detail screen shows the raw budget figure — the same number
            // web's `JobDetailResource` sends to anyone who can view the job
            // at all (only the cost *breakdown*, `jobCosting`, is gated
            // behind `viewJobCosts` on web; the budget itself isn't).
            'budget' => $job->budget === null ? null : (float) $job->budget,
            'assignments' => $job->activeAssignments->map(fn ($a) => [
                'role' => $a->role,
                'name' => $a->name,
            ])->all(),
            'openTasksCount' => $job->tasks()->open()->count(),
            'crewTime' => $this->crewTime($job),
            'myTasksComplete' => $this->myTasksComplete($request, $job),
            // Who is assigned as an apprentice on this job right now —
            // meaningful to a journeyman wondering who they've got, and to
            // a foreman deciding who still needs one.
            'apprenticeAssignments' => $job->apprenticeAssignments()
                ->with(['journeyman:id,name', 'apprentice:id,name'])
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'journeymanId' => $a->journeyman_id,
                    'journeymanName' => $a->journeyman->name,
                    'apprenticeId' => $a->apprentice_id,
                    'apprenticeName' => $a->apprentice->name,
                ])->values(),
            ...$this->apprenticeAssignmentOptions($request, $job),
        ]);
    }

    /**
     * `Api\V1\JobController::update()` — mobile's own Edit Job. Deliberately
     * matches what mobile's own "Continue to Job" create flow already
     * collects (name, a site from the client's own book, description, job
     * type, crew, dates) rather than web's fuller `JobEdit.tsx` (which also
     * lets a manager reassign the client/project/drawing/status/budget) —
     * same fields on the way in as on the way out. Scoped to the job's own
     * owner (`user_id`, exactly `JobPolicy::update()`'s own rule), not
     * `ElectricianJobAccess` — a manager correcting a job they raised is a
     * different question from a crew member's job-visibility scope, which
     * already lets any "unrestricted" role (PM included) see every job.
     */
    public function update(Request $request, Job $job): JsonResponse
    {
        abort_unless($job->user_id === $request->user()->id, 403);
        $job->assertNotLocked();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            // Optional, same as the create flow: a job can be on the books
            // before anyone has picked exactly which of the client's sites
            // it's at.
            'address_id' => [
                'nullable', 'integer',
                Rule::exists('client_addresses', 'id')->where('client_id', $job->client_id),
            ],
        ], [
            'name.required' => 'Job name is required',
            'team_id.required' => 'Pick the crew this job is handed to',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ]);

        $addressId = $data['address_id'] ?? null;
        unset($data['address_id']);

        $job->update($data);

        if ($addressId !== null) {
            $addresses = $this->sites->resolve((int) $job->client_id, [$addressId]);
            $this->sites->attach($job, $addresses);
        }

        $job->recordActivity('updated', 'Job details updated');

        return $this->ok([
            ...$this->summarize($job, $request),
            'description' => $job->description,
        ], "\"{$job->name}\" was updated.");
    }

    /**
     * The "Assign Apprentice" picker's own two lists — only for a foreman,
     * the one role that can actually make the assignment
     * (`Api\V1\JobApprenticeAssignmentController`).
     *
     * @return array<string, mixed>
     */
    private function apprenticeAssignmentOptions(Request $request, Job $job): array
    {
        if ($request->user()->foreman?->role !== Foreman::ROLE_FOREMAN) {
            return ['canAssignApprentice' => false];
        }

        $journeymen = $job->assignedJourneymen();

        return [
            'canAssignApprentice' => true,
            'assignableJourneymen' => $journeymen->map(fn (Foreman $j) => [
                'id' => $j->id,
                'name' => $j->name,
            ])->values(),
            'apprenticesByJourneyman' => $journeymen->mapWithKeys(
                fn (Foreman $j) => [$j->id => $j->teamApprentices()->get(['id', 'name'])->map(fn (Foreman $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                ])->values()],
            ),
        ];
    }

    /** Whether the requesting account is the crew register's junior tier — gates how much of a job's data it sees. */
    private function isApprentice(Request $request): bool
    {
        return $request->user()->foreman?->role === Foreman::ROLE_APPRENTICE;
    }

    /**
     * A foreman's targeted sign-off on one crew member's own portion of this
     * job — never anyone else's, whatever else happens to be ready at the
     * same moment. Reached from the task list, once that crew member's tasks
     * read as done.
     */
    public function approveForeman(Request $request, Job $job, Foreman $foreman): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $actingForeman = $request->user()->foreman;
        abort_unless(
            $actingForeman?->role === Foreman::ROLE_FOREMAN,
            403,
            'Only a foreman can approve a crew member’s work.',
        );

        if ($job->isLocked()) {
            return $this->fail('This job is already completed and can no longer be changed.', 409);
        }

        $completion = $job->foremanCompletions()->where('foreman_id', $foreman->id)->first();
        if ($completion === null || ! $completion->isReadyForReview()) {
            return $this->fail(
                "{$foreman->name} hasn't submitted their tasks for review yet.",
                422,
                ['code' => 'not_ready_for_review'],
            );
        }

        // Already approved — a second tap (a retried request, or two
        // foremen on the same job) changes nothing rather than erroring.
        if (! $completion->isApproved()) {
            $job->approveForeman($foreman->id);
        }

        return $this->ok([
            'jobId' => $job->id,
            'foremanId' => $foreman->id,
            'foremanName' => $foreman->name,
            'fullyApproved' => $job->isFullyApprovedByForemen(),
            'pendingForemen' => $job->pendingForemen(),
        ], "{$foreman->name}'s work is approved.");
    }

    /**
     * The requesting crew member's own start/submit/approve state on this
     * job — `null` for anyone the concept doesn't apply to (a foreman, who
     * oversees rather than carries a personal slice; an electrician with no
     * crew-register row), same reasoning as {@see myTasksComplete()}.
     *
     * @return array{myStartedAt: string|null, myReadyForReviewAt: string|null, myApprovedAt: string|null}|array{}
     */
    private function myForemanCompletion(Request $request, Job $job): array
    {
        $foreman = $request->user()->foreman;
        if ($foreman === null || ! in_array($foreman->role, Foreman::WORKER_ROLES, true)) {
            return [];
        }

        $completion = $job->foremanCompletions()->where('foreman_id', $foreman->id)->first();

        return [
            'myStartedAt' => $completion?->started_at?->toISOString(),
            'myReadyForReviewAt' => $completion?->ready_for_review_at?->toISOString(),
            'myApprovedAt' => $completion?->approved_at?->toISOString(),
        ];
    }

    /**
     * Whether the requesting crew member's own tasks on this job are all
     * done — null for anyone the concept doesn't apply to (a foreman
     * oversees every task, not a personal slice; an electrician has no
     * crew-register row at all). This is what the app uses to decide when
     * "Mark Job Complete" is even worth a journeyman or apprentice tapping:
     * their own part being done does not mean the *job* is done —
     * `changeStatus()` only actually completes it once every task, from
     * every crew member, is closed.
     */
    private function myTasksComplete(Request $request, Job $job): ?bool
    {
        $foreman = $request->user()->foreman;
        if ($foreman === null || ! in_array($foreman->role, Foreman::WORKER_ROLES, true)) {
            return null;
        }

        $myTasks = $job->tasks()->where('foreman_id', $foreman->id);
        if (! $myTasks->exists()) {
            return null;
        }

        return ! $myTasks->open()->exists();
    }

    /**
     * How much time every journeyman/apprentice on this job has put in — for
     * a foreman checking in on work they don't personally do (a crew member
     * already sees their own clock via `/timer`). A job is not one person's
     * anymore: it is broken into tasks each with their own worker, so this
     * reads every distinct one across the job's tasks, not just `job.foreman`
     * (the single header field, which can even be a foreman's own register
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
            ->whereIn('role', Foreman::WORKER_ROLES)
            ->whereNotNull('user_id')
            ->orderBy('name')
            ->get();

        $completions = $job->foremanCompletions()
            ->whereIn('foreman_id', $foremanIds)
            ->get()
            ->keyBy('foreman_id');

        return $foremen->map(function (Foreman $foreman) use ($job, $completions) {
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
                // This crew member's own portion, signed off — a foreman's
                // crew list should read "Completed" for them, not whatever
                // their last timer session status happened to be (usually
                // "paused", since completing stops the clock rather than
                // resuming it).
                'approvedAt' => $completions->get($foreman->id)?->approved_at?->toISOString(),
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
            // Starting a job is the crew's own call, not the foreman's — a
            // foreman oversees the job rather than running it day to day, so
            // their account never gets to be the one that puts a job on the
            // clock. `Foreman::role` (the crew register), not `User::role`,
            // is the authority here — the same distinction
            // `ElectricianJobAccess`/`JobSchedulePolicy` already draw.
            $foreman = $request->user()->foreman;
            if ($foreman?->role === Foreman::ROLE_FOREMAN) {
                return $this->fail('Only a journeyman or apprentice can start this job.', 403);
            }

            // An apprentice never starts a job alone — a journeyman or
            // foreman needs to actually be on the crew, whether running a
            // task themselves (`foreman_id`) or overseeing one
            // (`supervisor_id`), before the job can go on the clock.
            if ($foreman?->role === Foreman::ROLE_APPRENTICE && ! $job->hasSeniorCrewAssigned()) {
                return $this->fail(
                    'An apprentice cannot start a job without a foreman or journeyman on the crew.',
                    403,
                );
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

            // This foreman's own start, recorded independent of the job's
            // single shared status below — another foreman already having
            // started (or finished) theirs must never make this tap a
            // no-op for this one. `changeStatus()` on the job itself still
            // runs too, right below, exactly as before: the *first* foreman
            // to start still puts the job on the clock for `hasStarted()`
            // gates elsewhere (checklist, notes, timer) that only ever
            // understood one shared "has work begun on this job" flag.
            if ($foreman !== null) {
                $job->markForemanStarted($foreman->id);
            }
        }

        if ($data['status'] === 'completed') {
            // The same `Foreman::role` distinction the `in-progress` branch
            // above already draws: only a foreman's account can actually
            // close a job out. Everyone else completing their own tasks only
            // ever gets their own portion as far as ready-for-review — never
            // gated on any other crew member's still-open work (see
            // `prepareCompletion()`'s own doc comment).
            $foreman = $request->user()->foreman;
            $isForeman = $foreman?->role === Foreman::ROLE_FOREMAN;

            if (! $isForeman) {
                if ($foreman === null) {
                    // No crew-register row at all — an assignment-based
                    // electrician, not part of the foreman/supervisor split
                    // (`job_task_assignments`, not `foremen`). There is
                    // nothing to scope a per-foreman submission to, so this
                    // is the same single, whole-job gate as before
                    // per-foreman tracking existed — hours are finalized
                    // right now too, since there is no later "every foreman
                    // approved" moment on this job to defer it to.
                    if ($blocker = $this->prepareLegacyCompletion($request, $job, $data['reason'] ?? null)) {
                        return $blocker;
                    }

                    $job->markReadyForReview();

                    return $this->ok([
                        'jobId' => $job->id,
                        'from' => $job->status,
                        'to' => $job->status,
                        'readyForReviewAt' => $job->ready_for_review_at?->toISOString(),
                    ], 'Marked ready for foreman review.');
                }

                if ($blocker = $this->prepareCompletion($request, $job, $foreman)) {
                    return $blocker;
                }

                $job->markForemanReadyForReview($foreman->id);

                return $this->ok([
                    'jobId' => $job->id,
                    'from' => $job->status,
                    'to' => $job->status,
                    'readyForReviewAt' => now()->toISOString(),
                ], 'Your tasks are marked ready for foreman review.');
            }

            // This is the final close-out only — approving any one
            // foreman's own portion happens individually, from the task
            // list, via `approveForeman()` above. A job with real
            // per-foreman rows only reaches here once every one of them is
            // already approved that way; a legacy, assignment-only job (no
            // per-foreman rows) has no individual approvals to wait on, only
            // its own single whole-job `readyForReviewAt`.
            $hasForemanRows = $job->assignedForemanIds()->isNotEmpty();

            if ($hasForemanRows) {
                if (! $job->isFullyApprovedByForemen()) {
                    return $this->fail(
                        'Waiting for the crew to be reviewed and approved first.',
                        422,
                        ['code' => 'not_ready_for_review', 'pendingForemen' => $job->pendingForemen()],
                    );
                }

                if ($blocker = $this->finalizeHours($job, $data['reason'] ?? null)) {
                    return $blocker;
                }
            } elseif (! $job->isReadyForReview()) {
                return $this->fail(
                    'Waiting for the crew to finish their tasks first.',
                    422,
                    ['code' => 'not_ready_for_review'],
                );
            }
            // Else: legacy job, already ready for review — its hours were
            // finalized already at submission (`prepareLegacyCompletion()`),
            // nothing left to do but let it close below.
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
     * The whole-job gate this endpoint used before per-foreman tracking
     * existed — still the right one for a job with no `foremen`-table
     * staffing at all (an assignment-only crew, `job_task_assignments`),
     * where there is no foreman to scope a submission to and so no later
     * "every foreman approved" moment to defer hours-finalizing to either.
     *
     * Combines the old `prepareCompletion()` (open-task check, stop the
     * clock) with `finalizeHours()` in one step, exactly as it always ran.
     */
    private function prepareLegacyCompletion(Request $request, Job $job, ?string $reason): ?JsonResponse
    {
        $openTasks = $job->tasks()->open()->count();
        if ($openTasks > 0) {
            return $this->fail(
                "This job isn't done yet — {$openTasks} open ".
                str('task')->plural($openTasks).' across the crew still need to be completed.',
                422,
                ['code' => 'tasks_incomplete', 'openTasksCount' => $openTasks],
            );
        }

        $active = $this->timer->active($request->user());
        if ($active !== null && $active->job_id === $job->id) {
            $this->timer->stop($active);
        }

        return $this->finalizeHours($job, $reason);
    }

    /**
     * Gates one foreman marking their own slice of the job ready for review.
     *
     * Scoped to this foreman's own tasks only — a job is not one foreman's
     * to close, but neither is any *one* foreman's submission anyone else's
     * to block: another foreman's still-open tasks never stop this one from
     * submitting theirs (`Job::markForemanReadyForReview()` records it
     * independently; the job as a whole only closes once every foreman's
     * row is approved — see `changeStatus()`/`finalizeHours()`).
     *
     * If the technician still has a clock running on this job, it's stopped
     * first so its hours count. Returns a 422 response if this foreman
     * isn't actually done yet, or null to let `changeStatus()` proceed.
     */
    private function prepareCompletion(Request $request, Job $job, Foreman $foreman): ?JsonResponse
    {
        $openTasks = $job->myOpenTasksCount($foreman->id);
        if ($openTasks > 0) {
            return $this->fail(
                "You still have {$openTasks} open ".
                str('task')->plural($openTasks).' on this job.',
                422,
                ['code' => 'tasks_incomplete', 'openTasksCount' => $openTasks],
            );
        }

        $active = $this->timer->active($request->user());
        if ($active !== null && $active->job_id === $job->id) {
            $this->timer->stop($active);
        }

        return null;
    }

    /**
     * The last step before a job actually closes — called once every
     * assigned foreman's own portion has been approved, never before, since
     * the total worked time is job-wide (every foreman's hours combined),
     * not any one of theirs.
     *
     * The total is compared against `Job::estimated_hours`: if it ran over,
     * a reason is required (the same "why the extra time" the web app shows
     * later) before the job is allowed to close — supplied by the
     * foreman's own request here, since they are the one closing it out.
     * All three numbers are saved on the job itself so the web app has them.
     *
     * Returns a 422 response if closing should be blocked, or null to let
     * `changeStatus()` proceed.
     */
    private function finalizeHours(Job $job, ?string $reason): ?JsonResponse
    {
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
    private function summarize(Job $job, Request $request): array
    {
        $basics = [
            'id' => $job->id,
            'name' => $job->name,
            'client' => $job->client,
            // Not shown anywhere on the read-only crew view — carried so
            // Edit Job (a manager-only action, see `update()` above) can
            // preselect this job's own crew and pull its client's address
            // book to offer as sites, without a second round trip inventing
            // a shape `show()` doesn't already have.
            'clientId' => $job->client_id,
            'teamId' => $job->team_id,
            'addressId' => $job->addresses->first()?->id,
            'location' => $job->location,
            /*
             * The site's point, for the crew app: GPS check-in, geofencing and
             * on-site status all measure against this. Null on a job whose
             * address was typed rather than chosen, which the app has to treat
             * as "no geofence" rather than as the origin.
             */
            'latitude' => $job->latitude === null ? null : (float) $job->latitude,
            'longitude' => $job->longitude === null ? null : (float) $job->longitude,
            // Metres. Null lets the app fall back to its own 100 m default
            // rather than every job needing one set explicitly.
            'geofenceRadius' => $job->geofence_radius,
            'placeId' => $job->place_id,
            'jobType' => $job->job_type,
            'status' => $job->status,
            'priority' => $job->priority,
            'startDate' => $job->start_date?->toDateString(),
            'endDate' => $job->end_date?->toDateString(),
            'foreman' => $job->foreman ? [
                'name' => $job->foreman->name,
                'initials' => $job->foreman->initials,
                // "Foreman", "Journeyman" or "Apprentice" — the register row
                // this points to isn't always a foreman despite the
                // relation's name (see `Foreman`'s own doc comment), so the
                // app must not hardcode the label either.
                'role' => $job->foreman->roleLabel(),
            ] : null,
        ];

        // An apprentice sees the job exists and where/what it is — nothing
        // about hours, cost, or the task-review workflow, none of which they
        // can act on (see `BlockApprenticeAccess`).
        if ($this->isApprentice($request)) {
            return $basics;
        }

        return [
            ...$basics,
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
            // null again the moment a foreman reopens one. Only a
            // foreman's own tap while this is set actually finishes the
            // job (`changeStatus()`).
            'readyForReviewAt' => $job->ready_for_review_at?->toISOString(),
            ...$this->myForemanCompletion($request, $job),
            // Who the job is still waiting on before it can actually close —
            // meaningful to a journeyman wondering why the job isn't done
            // once their own part is approved, and to a foreman picking whom
            // to approve next from the task list.
            'pendingForemen' => $job->pendingForemen(),
            // Whose own portion is submitted and waiting on a foreman's
            // targeted approve tap (`approveForeman()`) right now.
            'foremenReadyForReview' => $job->readyForemen(),
        ];
    }
}
