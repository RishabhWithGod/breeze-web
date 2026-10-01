<?php

namespace App\Http\Controllers;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Rules\UsPhoneNumber;
use App\Services\Company\ManagerRegistrar;
use App\Services\Company\TeamInvitations;
use App\Support\CompanyRule;
use App\Support\UsPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team Setup: invite the crew and put them on teams, during first-time setup.
 *
 * Only the company's managers (a project manager or its administrator) use it, and only
 * while the company is still getting started — afterwards the Teams register is where
 * the crew is managed. Inviting a project manager is the owner's alone.
 */
class TeamSetupController extends Controller
{
    public function __construct(
        private readonly TeamInvitations $invitations,
        private readonly ManagerRegistrar $managers,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $this->authorizeManager($request);

        $company = CompanyProfile::query()->findOrFail($request->user()->company_id);

        // Setup is the only time this screen is the way in; after that it is the register.
        if ($company->onboarding_finished_at !== null) {
            return redirect()->route('teams.index')->with('warning', 'Setup is finished — the team is managed here now.');
        }

        return Inertia::render('TeamSetup', [
            'invitations' => TeamInvitation::query()
                ->with('team:id,name')
                ->latest('id')
                ->get()
                ->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->id,
                    'name' => $invitation->name,
                    'email' => $invitation->email,
                    'phone' => $invitation->phone,
                    'role' => $invitation->role,
                    'teamId' => $invitation->team_id,
                    'teamName' => $invitation->team?->name,
                    'status' => $invitation->label(),
                    'sentAt' => $invitation->sent_at?->toISOString(),
                    'expiresAt' => $invitation->expires_at?->toISOString(),
                ])->values(),
            'teams' => Team::query()->withCount('members')->orderBy('name')->get()
                ->map(fn (Team $team) => ['id' => $team->id, 'name' => $team->name, 'members' => $team->members_count]),
            'roles' => $this->invitableRoles($request),
            'seats' => $this->invitations->seats($request->user()),
            'members' => Foreman::query()->count(),
        ]);
    }

    /** Sends an invitation to each row of the table. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request);

        $data = $request->validate([
            'invitations' => ['required', 'array', 'min:1', 'max:50'],
            'invitations.*.name' => ['required', 'string', 'min:2', 'max:255'],
            'invitations.*.email' => ['required', 'string', 'email', 'max:255', 'distinct:ignore_case'],
            'invitations.*.phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'invitations.*.role' => ['required', Rule::in($this->invitableRoles($request))],
            'invitations.*.team_id' => ['nullable', 'integer', CompanyRule::exists('teams')],
            // Optional: with one they can sign in at once; without, they are emailed a link to choose their own.
            'invitations.*.password' => ['nullable', 'string', Password::defaults()],
        ], [
            'invitations.required' => 'Add at least one person to invite.',
            'invitations.min' => 'Add at least one person to invite.',
            'invitations.*.name.required' => 'Enter a name.',
            'invitations.*.email.required' => 'Enter an email.',
            'invitations.*.email.email' => 'That does not look like an email address.',
            'invitations.*.email.distinct' => 'This email is on the list twice.',
            'invitations.*.role.required' => 'Choose a role.',
            'invitations.*.role.in' => 'You cannot invite someone to that role.',
            'invitations.*.password.min' => 'Use at least 8 characters.',
        ]);

        $rows = collect($data['invitations'])->map(fn (array $row) => [...$row, 'phone' => UsPhone::format($row['phone'] ?? null)]);

        // Someone who already has an account, or an invitation still open, is not invited twice.
        $emails = $rows->pluck('email')->map(fn ($email) => mb_strtolower(trim($email)));
        $taken = User::query()->whereIn('email', $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e))
            ->merge(TeamInvitation::query()->where('status', TeamInvitation::STATUS_PENDING)->where('expires_at', '>', now())
                ->whereIn('email', $emails)->pluck('email')->map(fn ($e) => mb_strtolower($e)));

        $errors = [];
        foreach ($rows as $index => $row) {
            if ($taken->contains(mb_strtolower(trim($row['email'])))) {
                $errors["invitations.{$index}.email"] = 'Already has an account or an open invitation.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $sent = $this->invitations->send($request->user(), $rows->all());

        $joined = collect($sent)->where('status', TeamInvitation::STATUS_ACCEPTED)->count();
        $invited = count($sent) - $joined;

        // Next in setup is the commodity list; the people just added carry on there.
        return redirect()->route('commodities.index')->with('success', collect([
            $joined > 0 ? $joined.' '.str('member')->plural($joined).' added and can sign in now' : null,
            $invited > 0 ? $invited.' '.str('invitation')->plural($invited).' sent' : null,
        ])->filter()->implode(' · ').'.');
    }

    public function resend(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorizeManager($request);
        abort_unless($invitation->status === TeamInvitation::STATUS_PENDING, 409, 'That invitation is no longer waiting.');

        $this->invitations->resend($invitation, $request->user());

        return back()->with('success', "Invitation sent again to {$invitation->email}.");
    }

    public function cancel(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->authorizeManager($request);
        abort_unless($invitation->status === TeamInvitation::STATUS_PENDING, 409, 'That invitation is no longer waiting.');

        $this->invitations->cancel($invitation);

        return back()->with('warning', "The invitation to {$invitation->email} was cancelled.");
    }

    /** The roles this person may invite someone as: a project manager only if they own the company. */
    private function invitableRoles(Request $request): array
    {
        return $this->managers->canAdd($request->user())
            ? TeamInvitation::ROLES
            : array_values(array_diff(TeamInvitation::ROLES, ['Project Manager']));
    }

    private function authorizeManager(Request $request): void
    {
        $user = $request->user();

        abort_unless($user->company_id !== null && $this->managers->isManager($user), 403);
    }
}
