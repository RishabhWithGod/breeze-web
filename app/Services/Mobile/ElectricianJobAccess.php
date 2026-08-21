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
 * work they are actually staffed on, either through the role-based
 * `job_assignments` table or through a task-level crew assignment
 * (`job_task_assignments`). A manager/foreman/PM/admin/owner — who might
 * also carry the mobile app — keeps the web app's unrestricted view, since
 * they already see every job's data there.
 *
 * This is the single place that answers "does this mobile user have access
 * to this job" — every mobile controller that touches a job goes through
 * it, so the rule can never drift between endpoints.
 */
class ElectricianJobAccess
{
    /** Roles that see every job on mobile too, matching their web access. */
    private const UNRESTRICTED = ['project manager', 'site supervisor', 'foreman', 'admin', 'owner', 'estimator'];

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

        return Job::query()->where(function (Builder $query) use ($user, $teamMemberId) {
            $query->whereHas(
                'assignments',
                fn (Builder $q) => $q->where('user_id', $user->id)->whereNull('released_at'),
            )->orWhereHas(
                'tasks.members',
                fn (Builder $q) => $q->where('team_members.id', $teamMemberId),
            );
        });
    }

    private function isUnrestricted(User $user): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), self::UNRESTRICTED, true);
    }
}
