<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\TeamInvitation;
use App\Services\Company\TeamInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * An emailed invitation, on the phone — public (the person has no account yet) and reached only
 * by the link's token. Accepting makes the account and signs the phone in, exactly as the web does.
 */
class InvitationController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TeamInvitations $invitations) {}

    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);

        if ($invitation === null) {
            return $this->ok(['state' => 'invalid', 'invitation' => null]);
        }

        return $this->ok([
            'state' => $invitation->isPending() ? 'ready' : $invitation->label(),
            'invitation' => $this->present($invitation),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->find($token);
        abort_if($invitation === null || ! $invitation->isPending(), 404, 'This invitation is no longer available.');

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->invitations->accept($invitation, $data['password']);

        return $this->ok(
            app(AuthController::class)->issueSession($user, $request),
            "Welcome to {$this->present($invitation)['company']}.",
        );
    }

    private function find(string $token): ?TeamInvitation
    {
        return TeamInvitation::withoutGlobalScopes()->with('team')->where('token_hash', TeamInvitation::hashOf($token))->first();
    }

    /** @return array<string, mixed> */
    private function present(TeamInvitation $invitation): array
    {
        return [
            'company' => CompanyProfile::query()->whereKey($invitation->company_id)->value('name'),
            'inviter' => $invitation->inviter?->name,
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'team' => $invitation->team?->name,
        ];
    }
}
