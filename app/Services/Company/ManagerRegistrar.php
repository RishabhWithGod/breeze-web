<?php

namespace App\Services\Company;

use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\Billing\SubscriptionSummary;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds another manager to a company.
 *
 * The one place it happens: from Add Member, by the company's owner. The new
 * manager joins the owner's company and goes straight into the app,
 * since the company is already set up and its terms already signed.
 */
class ManagerRegistrar
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function __construct(private readonly SubscriptionSummary $subscription) {}

    /** Whether this account holds a manager role. */
    public function isManager(User $user): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }

    /** Whether this account is the owner of its company — the one who set it up. */
    public function isOwner(?User $user): bool
    {
        return $user !== null
            && $user->company_id !== null
            && CompanyProfile::query()->whereKey($user->company_id)->value('user_id') === $user->id;
    }

    /**
     * Whether `$actor` may add and correct the company's managers: only its owner.
     * The same gate covers correcting the company's own details.
     */
    public function canAdd(?User $actor): bool
    {
        return $this->isOwner($actor) && $this->isManager($actor);
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
                $errorField => 'Your plan is full. Upgrade your plan to add another person.',
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
