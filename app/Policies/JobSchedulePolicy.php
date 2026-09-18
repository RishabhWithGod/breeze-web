<?php

namespace App\Policies;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\User;

/**
 * Who may do what to a schedule.
 *
 * Two questions, both of which must clear: *is this the kind of thing your
 * role does* (`users.role`, free text entered by the office), and *is it
 * your job* (`work_jobs.user_id` — the manager the job was raised under).
 * A foreman's role lets them replan a schedule; it was never meant to
 * let them replan a schedule on a job that is not theirs.
 *
 * Roles are matched case-insensitively on the job title stored against the
 * user.
 */
class JobSchedulePolicy
{
    /** Roles that may change the plan itself. */
    private const PLANNERS = ['project manager', 'foreman', 'estimator', 'admin', 'owner'];

    /** Roles that may staff a task. */
    private const STAFFERS = ['project manager', 'foreman', 'admin', 'owner'];

    /** Roles that may remove work from a schedule. */
    private const DELETERS = ['project manager', 'admin', 'owner'];

    /** Anyone signed in can read the shared plan — for a job that is theirs. */
    public function view(User $user, JobSchedule $schedule): bool
    {
        return $this->owns($user, $schedule->job);
    }

    /** Dates, working week, holidays, status. */
    public function update(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS) && $this->owns($user, $schedule->job);
    }

    public function createTask(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS) && $this->owns($user, $schedule->job);
    }

    /** Reordering and rescheduling are both changes to the plan. */
    public function reorder(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS) && $this->owns($user, $schedule->job);
    }

    public function updateTask(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::PLANNERS) && $this->owns($user, $task->job);
    }

    /**
     * Reopening work once it is already signed off — unchecking a completed
     * checklist line, or sending a task back during review.
     *
     * `updateTask()` alone (planner role + owning the job outright) is too
     * narrow here: a foreman is commonly assigned to watch a
     * specific task without being the job's own manager (`work_jobs.user_id`),
     * and reopening the work they are literally supervising is exactly their
     * job. `updateTask()` still applies on its own for a manager who owns the
     * job but isn't named on any task.
     */
    public function reopenTask(User $user, JobTask $task): bool
    {
        return $this->updateTask($user, $task) || $this->isTaskSupervisor($user, $task);
    }

    /**
     * Completing work is not a planning decision.
     *
     * A planner can close anything on their own jobs; anyone else can close a
     * task they are actually on, which is what lets a crew tick off their own
     * work without the office doing it for them — and being on the task at
     * all already implies it is a job they have a real reason to be on.
     */
    public function completeTask(User $user, JobTask $task): bool
    {
        return ($this->holds($user, self::PLANNERS) && $this->owns($user, $task->job))
            || $this->isAssigned($user, $task);
    }

    /** Recording a delay is reporting, so the same rule as completing. */
    public function delayTask(User $user, JobTask $task): bool
    {
        return $this->completeTask($user, $task);
    }

    public function assign(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::STAFFERS) && $this->owns($user, $task->job);
    }

    /** Putting an apprentice under a journeyman on this job — the same staffing authority as {@see assign()}. */
    public function assignApprentice(User $user, Job $job): bool
    {
        return $this->holds($user, self::STAFFERS) && $this->owns($user, $job);
    }

    public function deleteTask(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::DELETERS) && $this->owns($user, $task->job);
    }

    /** Comment who can see the work: the job's own manager, or whoever is on it. */
    public function comment(User $user, JobTask $task): bool
    {
        return $this->owns($user, $task->job) || $this->isAssigned($user, $task);
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user, JobSchedule $schedule): array
    {
        $owns = $this->owns($user, $schedule->job);

        return [
            'updateSchedule' => $this->holds($user, self::PLANNERS) && $owns,
            'createTask' => $this->holds($user, self::PLANNERS) && $owns,
            'reorder' => $this->holds($user, self::PLANNERS) && $owns,
            'assign' => $this->holds($user, self::STAFFERS) && $owns,
            'deleteTask' => $this->holds($user, self::DELETERS) && $owns,
            'comment' => $owns,
        ];
    }

    /* ------------------------------------------------------------- internals */

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }

    /**
     * Whether `$job` is this manager's own.
     *
     * A handful of call sites probe an ability with no real job in view yet —
     * a generic "does this role manage things at all" check on a stand-in
     * schedule that names none. Nothing to own means nothing to deny; the
     * role check alone answers those. Anywhere a job is actually named, this
     * is the check that matters.
     */
    private function owns(User $user, ?Job $job): bool
    {
        return $job === null || $job->user_id === $user->id;
    }

    /**
     * Whether this user is on the task, through either of the app's two
     * real staffing mechanics:
     *
     * - named as the task's foreman/supervisor (`job_tasks.foreman_id`/
     *   `supervisor_id`, linked to this account via `foremen.user_id`) —
     *   the mechanic every mobile-onboarded technician is actually staffed
     *   through (see `TechnicianController::syncForemanRoster()`), or
     * - the older name-matched `job_task_assignments` crew, where users and
     *   crew members are separate records and the link is the name — that
     *   is what the office types into both, matched case-insensitively.
     */
    private function isAssigned(User $user, JobTask $task): bool
    {
        $foremanId = $user->foreman?->id;
        if ($foremanId !== null
            && ($task->foreman_id === $foremanId || $task->supervisor_id === $foremanId)) {
            return true;
        }

        // A job raised before tasks carried their own foreman/supervisor
        // names its one crew lead on the job's own header field instead
        // (`work_jobs.foreman_id` — which, per `Foreman`'s own doc comment,
        // can be a supervisor's register row too) — the same legacy
        // fallback `Job::assignedForemen()` reads from when no task has one
        // of its own.
        if ($foremanId !== null
            && $task->foreman_id === null
            && $task->supervisor_id === null
            && $task->job?->foreman_id === $foremanId) {
            return true;
        }

        $name = mb_strtolower(trim($user->name));

        return $task->assignments
            ->contains(fn ($assignment) => mb_strtolower(trim((string) $assignment->member?->name)) === $name);
    }

    /**
     * Whether this user is a foreman with a real claim to this specific
     * task — named on it directly (`job_tasks.supervisor_id`), or, when the
     * task has no worker/foreman of its own, via the job's own legacy
     * header field (same fallback as {@see isAssigned()}). Deliberately
     * requires the foreman *role* on top of the assignment: a plain
     * journeyman or apprentice named the same way is still covered by
     * {@see isAssigned()} for everyday actions, but reopening already-signed-off
     * work is a foreman's call, not the crew's own.
     */
    private function isTaskSupervisor(User $user, JobTask $task): bool
    {
        $foreman = $user->foreman;
        if ($foreman === null || $foreman->role !== Foreman::ROLE_FOREMAN) {
            return false;
        }

        if ($task->supervisor_id === $foreman->id) {
            return true;
        }

        return $task->supervisor_id === null
            && $task->foreman_id === null
            && $task->job?->foreman_id === $foreman->id;
    }
}
