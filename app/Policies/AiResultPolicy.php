<?php

namespace App\Policies;

use App\Models\AiResult;
use App\Models\User;

/**
 * Reviewing, finalising and exporting all follow ownership of the underlying
 * takeoff; a finalised result can no longer be edited.
 */
class AiResultPolicy
{
    public function view(User $user, AiResult $result): bool
    {
        return $result->project->user_id === $user->id;
    }

    /** Symbol-level edits: approve, reject, rename, recount, merge, split. */
    public function review(User $user, AiResult $result): bool
    {
        return $this->view($user, $result) && ! $result->isFinalised();
    }

    public function finalise(User $user, AiResult $result): bool
    {
        return $this->view($user, $result) && ! $result->isFinalised();
    }

    /** Job and estimate creation need a signed-off result. */
    public function convert(User $user, AiResult $result): bool
    {
        return $this->view($user, $result) && $result->isFinalised();
    }
}
