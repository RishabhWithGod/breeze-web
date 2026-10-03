<?php

namespace App\Services\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\User;
use App\Services\Company\ManagerRegistrar;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may do what with change orders.
 *
 * A project manager (or admin/owner) sees every change order of the company, raises them for any of
 * its jobs and decides them. A foreman raises them for the jobs they are assigned to, sees and
 * changes only their own. Nobody else has the screen.
 */
class ChangeOrderAccess
{
    public function __construct(private readonly ManagerRegistrar $managers) {}

    public function isManager(User $user): bool
    {
        return $this->managers->isManager($user);
    }

    public function foreman(User $user): ?Foreman
    {
        $foreman = $user->foreman;

        return $foreman !== null && $foreman->role === Foreman::ROLE_FOREMAN ? $foreman : null;
    }

    public function canUse(User $user): bool
    {
        return $this->isManager($user) || $this->foreman($user) !== null;
    }

    /** The account the company's change orders are numbered and kept under. */
    public function ownerId(User $user): int
    {
        return (int) Ownership::bookOwnerId($user->id);
    }

    /**
     * @param  Builder<ChangeOrder>  $query
     * @return Builder<ChangeOrder>
     */
    public function visible(Builder $query, User $user): Builder
    {
        $query->where('owner_id', $this->ownerId($user));

        return $this->isManager($user) ? $query : $query->where('created_by', $user->id);
    }

    /**
     * The jobs a change order can be raised for: the company's, and for a foreman only theirs.
     *
     * @return Builder<Job>
     */
    public function jobs(User $user): Builder
    {
        $jobs = Job::query()->ownedBy($user)->whereNull('archived_at');

        if ($this->isManager($user)) {
            return $jobs;
        }

        $foremanId = $this->foreman($user)?->id;

        return $jobs->where(fn (Builder $q) => $q->where('foreman_id', $foremanId)
            ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->runBy($foremanId)));
    }

    public function canRaiseFor(User $user, Job $job): bool
    {
        return $this->canUse($user)
            && ! $job->isLocked()
            && $this->jobs($user)->whereKey($job->id)->exists();
    }

    public function canEdit(User $user, ChangeOrder $co): bool
    {
        return $co->isEditable() && ($this->isManager($user) || $co->created_by === $user->id);
    }

    public function canWithdraw(User $user, ChangeOrder $co): bool
    {
        return $co->status === ChangeOrder::STATUS_SUBMITTED && ($this->isManager($user) || $co->created_by === $user->id);
    }

    public function canDecide(User $user, ChangeOrder $co): bool
    {
        return $co->status === ChangeOrder::STATUS_SUBMITTED && $this->isManager($user);
    }

    public function canDelete(User $user, ChangeOrder $co): bool
    {
        return $co->status === ChangeOrder::STATUS_DRAFT && ($this->isManager($user) || $co->created_by === $user->id);
    }
}
