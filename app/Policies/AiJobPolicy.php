<?php

namespace App\Policies;

use App\Models\AiJob;
use App\Models\User;

class AiJobPolicy
{
    public function view(User $user, AiJob $aiJob): bool
    {
        return \App\Support\Ownership::owns($user, $aiJob->project->user_id);
    }

    public function update(User $user, AiJob $aiJob): bool
    {
        return $this->view($user, $aiJob);
    }
}
