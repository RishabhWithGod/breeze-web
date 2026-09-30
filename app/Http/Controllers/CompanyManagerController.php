<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\UsPhoneNumber;
use App\Services\Company\ManagerRegistrar;
use App\Support\UsPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Correcting a company's managers. Adding one is Add Member's job — see
 * {@see ManagerRegistrar}.
 */
class CompanyManagerController extends Controller
{
    /**
     * The Edit Member form, for a manager — the same screen a crew member's details
     * are corrected on, with the parts that only make sense for crew left out.
     */
    public function edit(Request $request, ManagerRegistrar $managers, User $manager): Response
    {
        [$isSelf, $isOwner] = $this->authorizeEdit($request, $managers, $manager);

        return Inertia::render('ForemanEdit', [
            'teams' => [],
            'roles' => [['value' => 'manager', 'label' => 'Manager']],
            'foreman' => [
                'id' => $manager->id,
                'name' => $manager->name,
                'initials' => $manager->initials,
                'role' => 'manager',
                'teamId' => null,
                'phone' => $manager->phone,
                'email' => $manager->email,
                'licenceNumber' => null,
                'joinedOn' => $manager->created_at?->toDateString(),
                'notes' => null,
            ],
            // Tells the form it is editing a manager, and where to save and return to.
            'manager' => [
                'saveUrl' => route('settings.payment.managers.update', $manager, absolute: false),
                'backUrl' => route('teams.index', ['tab' => 'managers'], absolute: false),
                // Someone's own sign-in email is changed from Security, not here.
                'canEditEmail' => $isOwner && ! $isSelf,
            ],
        ]);
    }

    /**
     * Correcting a manager's details.
     *
     * Only the company's owner may. Their own sign-in email and password are theirs
     * to change from Security, where a code is checked first — not here.
     */
    public function update(Request $request, ManagerRegistrar $managers, User $manager): RedirectResponse
    {
        [$isSelf] = $this->authorizeEdit($request, $managers, $manager);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            // Only the owner correcting someone else's sign-in address.
            'email' => $isSelf
                ? ['prohibited']
                : ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($manager->id)],
        ], [
            'name.required' => 'Enter the manager’s name.',
            'email.unique' => 'Someone already has an account with that email.',
            'email.prohibited' => 'Change your own email from Security.',
        ]);

        $manager->name = $data['name'];
        $manager->phone = UsPhone::format($data['phone'] ?? null);
        if (! $isSelf) {
            $manager->email = Str::lower($data['email']);
        }
        $manager->save();

        return redirect()
            ->route('teams.index', ['tab' => 'managers'])
            ->with('success', "{$manager->name}’s details were updated.");
    }

    /**
     * Who may correct a manager: only the company's owner (who may also correct
     * themselves). Every other manager, and any crew member, is refused.
     *
     * @return array{0: bool, 1: bool} whether it is themselves, and whether it is the owner asking
     */
    private function authorizeEdit(Request $request, ManagerRegistrar $managers, User $manager): array
    {
        $actor = $request->user();

        abort_unless($managers->canAdd($actor), 403);
        // A manager of another company, or someone who is not a manager: not found.
        abort_unless($manager->company_id === $actor->company_id && $managers->isManager($manager), 404);

        return [$manager->id === $actor->id, true];
    }
}
