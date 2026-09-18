<?php

namespace App\Services\Mobile;

use App\Models\Job;
use App\Models\User;
use App\Services\TimeTracking\TeamMemberResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which jobs a mobile session may see and act on.
 *
 * The web app has no per-job restriction at all — any signed-in user can
 * open any Job Detail page (jobs are shared company-wide there). The mobile
 * surface is deliberately stricter: a field electrician's app only shows
 * work they are actually staffed on, through any of the app's three real
 * staffing mechanics:
 * - the role-based `job_assignments` table (the "Assigned Crew" panel),
 * - a task-level crew assignment (`job_task_assignments`, from the full
 *   scheduling system), or
 * - being named as a task's foreman/supervisor (`job_tasks.foreman_id`/
 *   `supervisor_id`) — the register-based mechanic `JobTaskSetupController`
 *   actually uses when a job's tasks are first broken out, so a technician
 *   named there is staffed exactly as much as one added through either of
 *   the other two.
 * A manager/foreman/PM/admin/owner — who might also carry the mobile app —
 * keeps the web app's unrestricted view, since they already see every job's
 * data there.
 *
 * This is the single place that answers "does this mobile user have access
 * to this job" — every mobile controller that touches a job goes through
 * it, so the rule can never drift between endpoints.
 */
class ElectricianJobAccess
{
    /** Roles that see every job on mobile too, matching their web access. */
    private const UNRESTRICTED = ['project manager', 'foreman', 'journeyman', 'admin', 'owner', 'estimator'];

    public function __construct(private readonly TeamMemberResolver $resolver) {}

    public function canAccess(User $user, Job $job): bool
    {
        if ($this->isUnrestricted($user)) {
            return true;
        }

        return $this->assignedJobsQuery($user)->whereKey($job->id)->exists();
    }

    /** @return Builder<Job> */
    public function assignedJobsQuery(User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return Job::query();
        }

        $teamMemberId = $this->resolver->resolveFor($user)->id;
        // Null until a manager has given this technician both a team and a
        // role — see `TechnicianController::syncForemanRoster()`. Before
        // that, this signal simply contributes nothing, same as any other
        // web-created user with no `foremen` row.
        $foremanId = $user->foreman?->id;

        return Job::query()->where(function (Builder $query) use ($user, $teamMemberId, $foremanId) {
            $query->whereHas(
                'assignments',
                fn (Builder $q) => $q->where('user_id', $user->id)->whereNull('released_at'),
            )->orWhereHas(
                'tasks.members',
                fn (Builder $q) => $q->where('team_members.id', $teamMemberId),
            );

            if ($foremanId !== null) {
                $query->orWhere('foreman_id', $foremanId)
                    ->orWhereHas('tasks', fn (Builder $q) => $q->heldBy($foremanId));
            }
        });
    }

    private function isUnrestricted(User $user): bool
    {
        // A technician onboarded from the mobile app always stays restricted
        // to their own staffing, even once a manager corrects their role to
        // 'Foreman'/'Journeyman'/'Apprentice' — on mobile those are real
        // operational roles for a field crew member, not the web app's
        // managerial roles this list exists for. `registration_source` is
        // the only thing that still tells the two apart once the role
        // string is identical.
        if ($user->isFromMobile()) {
            return false;
        }

        return in_array(mb_strtolower(trim((string) $user->role)), self::UNRESTRICTED, true);
    }
}
