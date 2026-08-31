<?php

namespace App\Events;

use App\Models\TimeEntry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A manager approved a time entry. */
class TimeEntryApproved implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TimeEntry $entry) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->entry->job_id}")];
    }

    public function broadcastAs(): string
    {
        return 'time-entry.approved';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->entry->job_id,
            'entryId' => $this->entry->id,
            'userId' => $this->entry->user_id,
            'status' => $this->entry->status,
            'hours' => (float) $this->entry->hours,
        ];
    }
}
