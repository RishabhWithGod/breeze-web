<?php

namespace App\Policies;

use App\Models\User;

/**
 * Every user manages their own Breeze Bucks by construction — no route ever
 * accepts another user's id for self-service actions. This policy only
 * gates the two things that touch *other* people's points: awarding a bonus
 * and managing the reward catalog.
 */
class BreezeBucksPolicy
{
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    private const ADMINS = ['admin', 'owner'];

    public function award(User $user): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    public function manageCatalog(User $user): bool
    {
        return $this->holds($user, self::ADMINS);
    }

    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
