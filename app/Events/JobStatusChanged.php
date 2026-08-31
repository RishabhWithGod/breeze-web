<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A job moved from one status to another.
 *
 * Broadcast on `job.{id}` — anyone viewing that job's detail screen sees the
 * new status without refreshing. Jobs are shared company-wide (unlike a
 * takeoff project), so the channel has no owner check beyond "this job
 * exists" — the same rule the HTTP route already applies. No cost figures
 * belong on a job-wide channel; those stay in the authenticated,
 * per-role-redacted HTTP/Inertia response only.
 */
class JobStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Job $job,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->job->id}")];
    }

    public function broadcastAs(): string
    {
        return 'job.status-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->job->id,
            'name' => $this->job->name,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
