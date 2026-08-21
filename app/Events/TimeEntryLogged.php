<?php

namespace App\Events;

use App\Models\TimeEntry;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A time entry was logged or edited (`created`/`updated` — the submit/
 * approve/reject transitions have their own existing events).
 *
 * Broadcast on `job.{id}` so a job's crew/costing widgets can reflect new
 * hours without a refresh. Hours are not cost data — the app's own
 * `viewJobCosts` boundary is about dollars, not hours, which are already
 * shown to every role — so no redaction applies here; dollar figures
 * (`laborCost`/`billableAmount`) are simply never included.
 */
class TimeEntryLogged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->entry->job_id}")];
    }

    public function broadcastAs(): string
    {
        return 'time-entry.logged';
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
            'action' => $this->action,
        ];
    }
}
