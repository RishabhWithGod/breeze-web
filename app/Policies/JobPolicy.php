<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\User;

/** A job belongs to the manager who raised it. */
class JobPolicy
{
    public function view(User $user, Job $job): bool
    {
        return $job->user_id === $user->id;
    }

    public function update(User $user, Job $job): bool
    {
        return $this->view($user, $job);
    }

    public function delete(User $user, Job $job): bool
    {
        return $this->view($user, $job);
    }
}
