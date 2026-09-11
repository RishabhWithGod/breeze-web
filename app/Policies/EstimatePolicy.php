<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\User;

/** An estimate belongs to whoever manages the project (or client) it is for. */
class EstimatePolicy
{
    public function view(User $user, Estimate $estimate): bool
    {
        return $estimate->user_id === $user->id;
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return $this->view($user, $estimate);
    }

    public function delete(User $user, Estimate $estimate): bool
    {
        return $this->view($user, $estimate);
    }
}
