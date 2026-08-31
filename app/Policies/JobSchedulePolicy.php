<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\User;

/**
 * Who may do what to a schedule.
 *
 * Built on `users.role`, which already exists. The distinctions that matter on site:
 * anyone on the job can see the schedule and tick off their own work; changing the
 * plan, staffing it, or deleting work is a planning decision and belongs to the
 * people accountable for the date.
 *
 * Roles are matched case-insensitively on the job title stored against the user,
 * because that column is free text entered by the office.
 */
class JobSchedulePolicy
{
    /** Roles that may change the plan itself. */
    private const PLANNERS = ['project manager', 'site supervisor', 'estimator', 'admin', 'owner'];

    /** Roles that may staff a task. */
    private const STAFFERS = ['project manager', 'site supervisor', 'admin', 'owner'];

    /** Roles that may remove work from a schedule. */
    private const DELETERS = ['project manager', 'admin', 'owner'];

    /** Everyone signed in can read a schedule; it is the shared plan. */
    public function view(User $user, JobSchedule $schedule): bool
    {
        return true;
    }

    /** Dates, working week, holidays, status. */
    public function update(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS);
    }

    public function createTask(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS);
    }

    /** Reordering and rescheduling are both changes to the plan. */
    public function reorder(User $user, JobSchedule $schedule): bool
    {
        return $this->holds($user, self::PLANNERS);
    }

    public function updateTask(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::PLANNERS);
    }

    /**
     * Completing work is not a planning decision.
     *
     * A planner can close anything; anyone else can close a task they are actually on,
     * which is what lets a crew tick off their own work without the office doing it
     * for them.
     */
    public function completeTask(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::PLANNERS) || $this->isAssigned($user, $task);
    }

    /** Recording a delay is reporting, so the same rule as completing. */
    public function delayTask(User $user, JobTask $task): bool
    {
        return $this->completeTask($user, $task);
    }

    public function assign(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::STAFFERS);
    }

    public function deleteTask(User $user, JobTask $task): bool
    {
        return $this->holds($user, self::DELETERS);
    }

    /** Anyone who can see the schedule can comment on its work. */
    public function comment(User $user, JobTask $task): bool
    {
        return true;
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user, JobSchedule $schedule): array
    {
        return [
            'updateSchedule' => $this->update($user, $schedule),
            'createTask' => $this->createTask($user, $schedule),
            'reorder' => $this->reorder($user, $schedule),
            'assign' => $this->holds($user, self::STAFFERS),
            'deleteTask' => $this->holds($user, self::DELETERS),
            'comment' => true,
        ];
    }

    /* ------------------------------------------------------------- internals */

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }

    /**
     * Whether this user is on the task.
     *
     * Users and crew members are separate records, so the link is the name — that is
     * what the office types into both. Matched case-insensitively and trimmed.
     */
    private function isAssigned(User $user, JobTask $task): bool
    {
        $name = mb_strtolower(trim($user->name));

        return $task->assignments
            ->contains(fn ($assignment) => mb_strtolower(trim((string) $assignment->member?->name)) === $name);
    }
}
