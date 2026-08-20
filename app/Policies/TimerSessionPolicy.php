<?php

namespace App\Policies;

use App\Models\TimerSession;
use App\Models\User;

/** A timer belongs to whoever started it — nobody else may touch it. */
class TimerSessionPolicy
{
    public function view(User $user, TimerSession $session): bool
    {
        return $session->user_id === $user->id;
    }

    public function update(User $user, TimerSession $session): bool
    {
        return $session->user_id === $user->id;
    }
}
