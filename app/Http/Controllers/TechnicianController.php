<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Technicians\TechnicianApprovalService;
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
 *
 * The actual mutations live in `TechnicianApprovalService`, shared with the
 * mobile `Api\V1\TechnicianController` — this controller is just the
 * web-request shell (auth/validation/redirect) around it.
 */
class TechnicianController extends Controller
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    /**
     * Every mobile signup starts as 'Journeyman' — a manager corrects it here
     * to whichever of these three it actually is.
     */
    public const ROLES = ['Foreman', 'Journeyman', 'Apprentice'];

    public function __construct(
        private readonly TeamMemberResolver $resolver,
        private readonly TechnicianApprovalService $approvals,
    ) {}

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

        $this->approvals->approve($user, $request->user(), $data['team_id'], $data['role']);

        return back()->with('success', "{$user->name} is approved.");
    }

    public function reject(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $this->approvals->reject($user);

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
        $this->approvals->syncForemanRoster($user, $teamMember->team_id, $user->role);

        return back()->with('success', "{$user->name}'s details were updated.");
    }

    private function canManage(?User $user): bool
    {
        return $user !== null
            && in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }
}
