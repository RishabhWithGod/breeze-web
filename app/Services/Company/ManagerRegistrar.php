<?php

namespace App\Services\Company;

use App\Models\User;
use App\Services\Billing\SubscriptionSummary;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds another manager to a company.
 *
 * The one place it happens: from Add Member, by any existing manager. The new
 * manager joins the same company and goes straight into the app, since the
 * company is already set up and its terms already signed.
 */
class ManagerRegistrar
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function __construct(private readonly SubscriptionSummary $subscription) {}

    /** Whether this account holds a manager role. */
    public function isManager(User $user): bool
    {
        return ($user->isCompanyOwner() || in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true));
    }

    /**
     * Whether `$actor` may add and correct the company's managers: any manager,
     * not only the one who originally set the company up. The same gate covers
     * correcting the company's own details and offering "Manager" as a role on
     * Add Member / Team Setup invitations.
     */
    public function canAdd(?User $actor): bool
    {
        return $actor !== null && $this->isManager($actor);
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     *
     * @throws ValidationException when the plan has no room for another person
     */
    public function add(User $actor, array $data, string $errorField = 'email'): User
    {
        if (! $this->subscription->hasSeatAvailable($actor)) {
            throw ValidationException::withMessages([
                $errorField => 'No seats left. Buy more seats to add another person.',
            ]);
        }

        $manager = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Hash::make($data['password']),
            'role' => 'Project Manager',
        ]);
        $manager->company_id = $actor->company_id;
        $manager->save();

        return $manager;
    }
}
