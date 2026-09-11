<?php

namespace App\Policies;

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
 * A site supervisor's role lets them replan a schedule; it was never meant to
 * let them replan a schedule on a job that is not theirs.
 *
 * Roles are matched case-insensitively on the job title stored against the
 * user.
 */
class JobSchedulePolicy
{
    /** Roles that may change the plan itself. */
    private const PLANNERS = ['project manager', 'site supervisor', 'estimator', 'admin', 'owner'];

    /** Roles that may staff a task. */
    private const STAFFERS = ['project manager', 'site supervisor', 'admin', 'owner'];

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

        $name = mb_strtolower(trim($user->name));

        return $task->assignments
            ->contains(fn ($assignment) => mb_strtolower(trim((string) $assignment->member?->name)) === $name);
    }
}
