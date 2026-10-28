<?php

namespace App\Http\Controllers;

use App\Models\Foreman;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Billing\SubscriptionSummary;
use App\Services\Leave\LeaveAvailability;
use App\Services\Leave\LeaveRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Switching a team member's account off, and back on.
 *
 * Deactivating is not deleting: the person, their tasks, hours and every record they made stay
 * exactly where they were. What changes is that they cannot sign in (web or phone), are signed
 * out wherever they are, stop holding a seat, and their leave that has not ended is cancelled.
 * Turning it back on needs a free seat.
 */
class TeamMemberAccountController extends Controller
{
    public function __construct(
        private readonly SubscriptionSummary $subscription,
        private readonly LeaveAvailability $availability,
    ) {}

    public function deactivate(Request $request, Foreman $foreman): RedirectResponse
    {
        $actor = $request->user();
        $user = $this->accountOf($request, $foreman);

        if ($user->id === $actor->id) {
            return back()->with('warning', 'You cannot deactivate your own account.');
        }

        $role = mb_strtolower(trim((string) $user->role));
        if ($role === 'owner' || ($role === 'admin' && mb_strtolower(trim((string) $actor->role)) !== 'owner')) {
            return back()->with('warning', "Only an owner can deactivate {$user->name}.");
        }

        if (! $user->isActive()) {
            return back()->with('warning', "{$user->name}'s account is not active.");
        }

        $openTasks = DB::transaction(function () use ($user, $actor, $foreman) {
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

        $note = $openTasks > 0
            ? " They still have {$openTasks} open ".($openTasks === 1 ? 'task' : 'tasks').' to hand to someone else.'
            : '';

        return back()->with('success', "{$user->name} was deactivated. Their history is kept and their seat is free.{$note}");
    }

    public function reactivate(Request $request, Foreman $foreman): RedirectResponse
    {
        $user = $this->accountOf($request, $foreman);

        if ($user->status !== User::STATUS_INACTIVE) {
            return back()->with('warning', "{$user->name}'s account is already active.");
        }

        // Back on means a seat held again.
        if (! $this->subscription->hasSeatAvailable($request->user())) {
            return back()->withErrors(['seats' => 'No seats left. Buy more in Settings → Subscription, or deactivate someone else, to bring '.$user->name.' back.']);
        }

        $user->status = User::STATUS_ACTIVE;
        $user->save();

        return back()->with('success', "{$user->name} is active again.");
    }

    /** The account behind this team member — only a manager of the same company may act on it. */
    private function accountOf(Request $request, Foreman $foreman): User
    {
        abort_unless(LeaveRequests::isManager($request->user()), 403);

        $user = $foreman->user_id !== null ? User::query()->find($foreman->user_id) : null;

        abort_if(
            $user === null || $user->company_id !== $request->user()->company_id,
            404,
            'This team member has no account to switch off.',
        );

        return $user;
    }
}
