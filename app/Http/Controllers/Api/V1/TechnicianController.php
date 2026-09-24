<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TechnicianController as WebTechnicianController;
use App\Models\User;
use App\Services\Technicians\TechnicianApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile counterpart to web's `TechnicianController::approve`/`reject` — same
 * `TechnicianApprovalService`, same validation rules (a team and a role are
 * both required to approve), JSON instead of a redirect. This is what the
 * Dashboard/Teams screens' Approve/Reject buttons call; the roster + pending
 * list itself is `TeamController::index`, which also hands over the
 * `teamOptions`/`roleOptions` this approve call needs.
 */
class TechnicianController extends Controller
{
    use ApiResponses;

    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function __construct(private readonly TechnicianApprovalService $approvals) {}

    public function approve(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $data = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'role' => ['required', Rule::in(WebTechnicianController::ROLES)],
        ], [
            'team_id.required' => 'Pick a team before approving.',
            'role.required' => 'Pick a role before approving.',
        ]);

        $this->approvals->approve($user, $request->user(), $data['team_id'], $data['role']);

        return $this->ok(message: "{$user->name} is approved.");
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);
        abort_unless($user->isFromMobile(), 404);

        $this->approvals->reject($user);

        return $this->ok(message: "{$user->name}'s application was rejected.");
    }

    private function canManage(?User $user): bool
    {
        return $user !== null
            && in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }
}
