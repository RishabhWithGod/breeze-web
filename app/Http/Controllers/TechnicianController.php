<?php

namespace App\Http\Controllers;

use App\Models\Foreman;
use App\Models\User;
use App\Notifications\TechnicianApplicationStatusChanged;
use App\Services\TimeTracking\TeamMemberResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Actions on technicians who signed up from the mobile app — approve,
 * reject, change team/role. The list itself lives on the Teams page
 * (`TeamController::index()`) alongside the `foremen` register, not its own
 * screen, so a manager staffing a crew and approving a new technician is one
 * page, not two.
 */
class TechnicianController extends Controller
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    /**
     * Every mobile signup starts as 'Journeyman' — a manager corrects it here
     * to whichever of these three it actually is.
     */
    public const ROLES = ['Foreman', 'Journeyman', 'Apprentice'];

    /** What this page calls a technician's role, mapped to what the crew register calls it. */
    private const FOREMAN_ROLE_MAP = [
        'Foreman' => Foreman::ROLE_FOREMAN,
        'Journeyman' => Foreman::ROLE_JOURNEYMAN,
        'Apprentice' => Foreman::ROLE_APPRENTICE,
    ];

    public function __construct(private readonly TeamMemberResolver $resolver) {}

    /**
     * Approves a technician — or re-approves a previously rejected one, the
     * same action either way. Team and role are both required here: leaving
     * someone active with neither set is exactly the confusing half-staffed
     * state this endpoint should never produce.
     */
    public function approve(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'role' => ['required', Rule::in(self::ROLES)],
        ], [
            'team_id.required' => 'Pick a team before approving.',
            'role.required' => 'Pick a role before approving.',
        ]);

        // Deliberately not `$user->update([...])`: `status`/`approved_at`/
        // `approved_by` are kept out of `$fillable` so nothing else can ever
        // mass-assign them, which means `update()` would silently drop them
        // too. Direct property assignment bypasses that guard the one place
        // it is meant to be bypassed.
        $user->status = User::STATUS_ACTIVE;
        $user->approved_at = now();
        $user->approved_by = $request->user()->id;
        $user->role = $data['role'];
        $user->save();

        $teamMember = $this->resolver->resolveFor($user);
        $teamMember->update(['team_id' => $data['team_id']]);

        $this->syncForemanRoster($user, $data['team_id'], $data['role']);

        $user->notify(new TechnicianApplicationStatusChanged($user, TechnicianApplicationStatusChanged::APPROVED));

        return back()->with('success', "{$user->name} is approved.");
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $user->status = User::STATUS_REJECTED;
        $user->save();

        $user->notify(new TechnicianApplicationStatusChanged($user, TechnicianApplicationStatusChanged::REJECTED));

        return back()->with('warning', "{$user->name}'s application was rejected.");
    }

    /**
     * Changes an already-approved technician's team and/or role — the
     * correction a manager makes once they know whether someone is really a
     * foreman or a site supervisor, independent of the approval decision.
     */
    public function assignTeam(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $data = $request->validate([
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'role' => ['sometimes', 'nullable', Rule::in(self::ROLES)],
        ]);

        $teamMember = $this->resolver->resolveFor($user);

        if ($request->has('team_id')) {
            $teamMember->update(['team_id' => $data['team_id']]);
        }

        if (! empty($data['role'])) {
            $user->role = $data['role'];
            $user->save();
        }

        // Only once both a team and a valid role are known — whichever of
        // the two this call just set, plus whatever the other one already
        // was — does this technician belong on their team's actual roster.
        $this->syncForemanRoster($user, $teamMember->team_id, $user->role);

        return back()->with('success', "{$user->name}'s details were updated.");
    }

    /**
     * Puts (or moves) an approved technician onto the real crew register —
     * the same `foremen` table every web-created crew member is on — once
     * they have both a team and a role. Until then they stay in the
     * Teams page's separate technician list; this is what removes them from
     * it and into their team's card the moment both are set.
     */
    private function syncForemanRoster(User $user, ?int $teamId, ?string $role): void
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

    private function canManage(?User $user): bool
    {
        return $user !== null
            && in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }
}
