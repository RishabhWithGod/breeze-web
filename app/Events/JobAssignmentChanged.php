<?php

namespace App\Events;

use App\Models\JobAssignment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Someone was staffed onto a job, or released from it. */
class JobAssignmentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly JobAssignment $assignment,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->assignment->job_id}")];
    }

    public function broadcastAs(): string
    {
        return 'job.assignment-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->assignment->job_id,
            'assignmentId' => $this->assignment->id,
            'role' => $this->assignment->role,
            'name' => $this->assignment->name,
            'action' => $this->action,
        ];
    }
}
