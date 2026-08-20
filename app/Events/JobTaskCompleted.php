<?php

namespace App\Events;

use App\Models\JobTask;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A task was marked complete, by the user who completed it. */
class JobTaskCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly JobTask $task,
        public readonly User $completedBy,
    ) {}
}
