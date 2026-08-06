<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/** A takeoff and everything derived from it belongs to whoever uploaded it. */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }
}
