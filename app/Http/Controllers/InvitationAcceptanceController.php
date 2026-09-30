<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\TeamInvitation;
use App\Services\Company\TeamInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an emailed invitation lands. Public — the person has no account yet — and
 * reached only by the link's token, of which only a hash is kept.
 */
class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly TeamInvitations $invitations) {}

    public function show(string $token): Response
    {
        $invitation = $this->find($token);

        if ($invitation === null) {
            return Inertia::render('AcceptInvitation', ['state' => 'invalid', 'invitation' => null]);
        }

        if (! $invitation->isPending()) {
            return Inertia::render('AcceptInvitation', [
                'state' => $invitation->label(),
                'invitation' => $this->present($invitation),
            ]);
        }

        return Inertia::render('AcceptInvitation', [
            'state' => 'ready',
            'invitation' => $this->present($invitation),
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);

        abort_if($invitation === null || ! $invitation->isPending(), 404);

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $this->invitations->accept($invitation, $data['password']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home')->with('success', "Welcome to {$this->present($invitation)['company']}.");
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
