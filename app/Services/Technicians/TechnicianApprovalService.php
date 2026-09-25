<?php

namespace App\Services\Technicians;

use App\Models\Foreman;
use App\Models\User;
use App\Notifications\TechnicianApplicationStatusChanged;
use App\Services\TimeTracking\TeamMemberResolver;

/**
 * Approve/reject a mobile technician signup, and sync them onto the real
 * `foremen` roster once they have both a team and a role — the one place
 * this mutation happens, shared by web's `TechnicianController` and the
 * mobile `Api\V1\TechnicianController` so a decision made from either surface
 * behaves identically.
 */
class TechnicianApprovalService
{
    /** What the technician forms call a role, mapped to what the crew register calls it. */
    private const FOREMAN_ROLE_MAP = [
        'Foreman' => Foreman::ROLE_FOREMAN,
        'Journeyman' => Foreman::ROLE_JOURNEYMAN,
        'Apprentice' => Foreman::ROLE_APPRENTICE,
    ];

    public function __construct(private readonly TeamMemberResolver $resolver) {}

    /**
     * Approves a technician — or re-approves a previously rejected one, the
     * same action either way. Team and role are both required by callers:
     * leaving someone active with neither set is exactly the confusing
     * half-staffed state this should never produce.
     */
    public function approve(User $user, User $actor, int $teamId, string $role): void
    {
        // Deliberately not `$user->update([...])`: `status`/`approved_at`/
        // `approved_by` are kept out of `$fillable` so nothing else can ever
        // mass-assign them, which means `update()` would silently drop them
        // too. Direct property assignment bypasses that guard the one place
        // it is meant to be bypassed.
        $user->status = User::STATUS_ACTIVE;
        $user->approved_at = now();
        $user->approved_by = $actor->id;
        $user->role = $role;
        $user->save();

        $teamMember = $this->resolver->resolveFor($user);
        $teamMember->update(['team_id' => $teamId]);

        $this->syncForemanRoster($user, $teamId, $role);

        $user->notify(new TechnicianApplicationStatusChanged($user, TechnicianApplicationStatusChanged::APPROVED));
    }

    public function reject(User $user): void
    {
        $user->status = User::STATUS_REJECTED;
        $user->save();

        $user->notify(new TechnicianApplicationStatusChanged($user, TechnicianApplicationStatusChanged::REJECTED));
    }

    /**
     * Puts (or moves) an approved technician onto the real crew register —
     * the same `foremen` table every web-created crew member is on — once
     * they have both a team and a role. Until then they stay in the
     * pending/technician list; this is what removes them from it.
     */
    public function syncForemanRoster(User $user, ?int $teamId, ?string $role): void
    {
        if ($teamId === null || ! isset(self::FOREMAN_ROLE_MAP[$role])) {
            return;
        }

        // Direct property assignment, not `updateOrCreate([...])`: `user_id`
        // is deliberately not in `Foreman::$fillable` (nothing should ever
        // let a request set which account a register row is linked to), so
        // mass assignment would silently drop it on a newly created row.
        $foreman = Foreman::query()->firstWhere('user_id', $user->id) ?? new Foreman;
        $foreman->user_id = $user->id;
        $foreman->name = $user->name;
        $foreman->initials = User::initialsFor($user->name);
        $foreman->phone = $user->phone;
        $foreman->email = $user->email;
        $foreman->team_id = $teamId;
        $foreman->role = self::FOREMAN_ROLE_MAP[$role];

        // The day a manager approved them, not the day they happened to be
        // corrected onto a team — and only ever set once: a manager who
        // later edits it from the register is recording the real date, not
        // a guess this sync should overwrite on the next role/team change.
        if ($foreman->started_on === null) {
            $foreman->started_on = ($user->approved_at ?? now())->toDateString();
        }
        $foreman->save();
    }
}
