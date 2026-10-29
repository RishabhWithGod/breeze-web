<?php

namespace App\Services\Team;

use App\Models\Foreman;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Billing\SubscriptionSummary;
use App\Services\Leave\LeaveAvailability;
use App\Services\Leave\LeaveRequests;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Switching a team member's account off and on — the one place web and the app both do it.
 *
 * Deactivating is not deleting: the person, their tasks, hours and records stay. They cannot sign in,
 * are signed out wherever they are, stop holding a seat, and leave that has not ended is cancelled.
 * Turning it back on needs a free seat.
 */
class MemberAccounts
{
    public function __construct(
        private readonly SubscriptionSummary $subscription,
        private readonly LeaveAvailability $availability,
    ) {}

    /** The account behind a roster member, when it is in the viewer's company; null otherwise. */
    public function accountOf(User $viewer, Foreman $foreman): ?User
    {
        $user = $foreman->user_id !== null ? User::query()->find($foreman->user_id) : null;

        return $user !== null && $user->company_id === $viewer->company_id ? $user : null;
    }

    /**
     * What the viewer may do to this account.
     *
     * @return array{status: string, canDeactivate: bool, canReactivate: bool, isYou: bool}
     */
    public function describe(User $viewer, User $user): array
    {
        $role = mb_strtolower(trim((string) $user->role));
        $manager = LeaveRequests::isManager($viewer) && $user->company_id === $viewer->company_id;

        return [
            'status' => $user->status,
            'canDeactivate' => $manager
                && $user->isActive()
                && $user->id !== $viewer->id
                && ! $user->isCompanyOwner()
                && $role !== 'owner'
                && ($role !== 'admin' || $this->ownerLike($viewer)),
            'canReactivate' => $manager && $user->status === User::STATUS_INACTIVE,
            'isYou' => $user->id === $viewer->id,
        ];
    }

    /**
     * @return int how many open tasks the person still holds
     *
     * @throws RuntimeException with the reason, when it may not be done
     */
    public function deactivate(User $actor, Foreman $foreman, User $user): int
    {
        if ($user->id === $actor->id) {
            throw new RuntimeException('You cannot deactivate your own account.');
        }

        $role = mb_strtolower(trim((string) $user->role));
        if ($user->isCompanyOwner() || $role === 'owner' || ($role === 'admin' && ! $this->ownerLike($actor))) {
            throw new RuntimeException("Only an owner can deactivate {$user->name}.");
        }

        if (! $user->isActive()) {
            throw new RuntimeException("{$user->name}'s account is not active.");
        }

        return DB::transaction(function () use ($user, $actor, $foreman) {
            $user->status = User::STATUS_INACTIVE;
            $user->save();

            // Signed out everywhere: the phone's tokens and every web session.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            // Leave that has not ended is no longer going to happen; what was already taken stays on record.
            $today = $this->availability->today($user->company_id)->toDateString();
            LeaveRequest::query()
                ->ofCompany($user->company_id)
                ->where('user_id', $user->id)
                ->holding()
                ->where('end_date', '>=', $today)
                ->update([
                    'status' => LeaveRequest::STATUS_CANCELLED,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                    'decision_note' => 'Account deactivated.',
                    'updated_at' => now(),
                ]);

            return $foreman->tasks()->open()->count();
        });
    }

    /** @throws RuntimeException with the reason, when it may not be done */
    public function reactivate(User $actor, User $user): void
    {
        if ($user->status !== User::STATUS_INACTIVE) {
            throw new RuntimeException("{$user->name}'s account is already active.");
        }

        if (! $this->subscription->hasSeatAvailable($actor)) {
            throw new RuntimeException('No seats left. Buy more seats, or deactivate someone else, to bring '.$user->name.' back.');
        }

        $user->status = User::STATUS_ACTIVE;
        $user->save();
    }

    private function ownerLike(User $user): bool
    {
        return $user->isCompanyOwner() || mb_strtolower(trim((string) $user->role)) === 'owner';
    }
}
