<?php

namespace App\Policies;

use App\Models\User;

/**
 * Payment Settings holds processor credentials and billing defaults — money
 * configuration, not a schedule. The entire screen, not just its mutations,
 * is restricted to managers, unlike Invoices/Estimates which anyone may view.
 */
class PaymentSettingsPolicy
{
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    public function manage(User $user): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
