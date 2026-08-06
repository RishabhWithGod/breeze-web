<?php

namespace App\Policies;

use App\Models\AiJob;
use App\Models\User;

class AiJobPolicy
{
    public function view(User $user, AiJob $aiJob): bool
    {
        return $aiJob->project->user_id === $user->id;
    }

    public function update(User $user, AiJob $aiJob): bool
    {
        return $this->view($user, $aiJob);
    }
}
