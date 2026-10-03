<?php

namespace App\Services\Mobile;

use App\Models\Foreman;
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
    private const UNRESTRICTED = ['manager', 'project manager', 'foreman', 'journeyman', 'admin', 'owner', 'estimator'];

    /**
     * Roles whose job *list* is every job in their company. Everyone else —
     * foreman, journeyman, apprentice, estimator, technician — lists only
     * the jobs they are assigned to.
     */
    private const LIST_ALL = ['manager', 'project manager', 'admin', 'owner'];

    public function __construct(private readonly TeamMemberResolver $resolver) {}

    public function canAccess(User $user, Job $job): bool
    {
        // Seeing every job still means every job of their own company.
        return $this->assignedJobsQuery($user)->whereKey($job->id)->exists();
    }

    /** @return Builder<Job> */
    public function assignedJobsQuery(User $user): Builder
    {
        if ($this->isUnrestricted($user)) {
            return Job::query()->inCompanyOf($user);
        }

        return $this->staffedJobsQuery($user);
    }

    /**
     * What the mobile job *list* shows. Only a manager-level role (manager,
     * project manager, admin, owner) lists every job of their company — the
     * jobs of all their members. Every other role lists only the work they
     * are actually assigned to, even when [canAccess] would let them open
     * more: a field lead scrolling a feed of every job in the company is
     * noise, not a worklist.
     *
     * @return Builder<Job>
     */
    public function listedJobsQuery(User $user): Builder
    {
        if ($this->listsEveryJob($user)) {
            return Job::query()->inCompanyOf($user);
        }

        return $this->staffedJobsQuery($user);
    }

    private function listsEveryJob(User $user): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), self::LIST_ALL, true);
    }

    /**
     * Only the jobs this user is explicitly assigned to, whatever their role
     * — a manager's company-wide list is not "their" work. What "My Schedule"
     * plans around.
     *
     * @return Builder<Job>
     */
    public function staffedJobs(User $user): Builder
    {
        return $this->staffedJobsQuery($user);
    }

    /**
     * Only the jobs this user is explicitly staffed on — never the
     * company-wide view.
     *
     * @return Builder<Job>
     */
    private function staffedJobsQuery(User $user): Builder
    {
        // An apprentice's access is entirely the explicit Foreman→
        // Journeyman→Apprentice assignment a foreman sets from the Job
        // Detail screen — never the broader staffing mechanics
        // (`job_assignments`, task membership, a task's own `foreman_id`)
        // every other crew role is scoped through below. "Only their
        // assigned job" means only this, on purpose.
        if ($user->foreman?->role === Foreman::ROLE_APPRENTICE) {
            $foremanId = $user->foreman->id;

            return Job::query()->whereHas(
                'apprenticeAssignments',
                fn (Builder $q) => $q->where('apprentice_id', $foremanId),
            );
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
        // A manager-level role is the exception: even on a mobile-registered
        // account it must open every job its list shows.
        if ($user->isFromMobile() && ! $this->listsEveryJob($user)) {
            return false;
        }

        return in_array(mb_strtolower(trim((string) $user->role)), self::UNRESTRICTED, true);
    }
}
