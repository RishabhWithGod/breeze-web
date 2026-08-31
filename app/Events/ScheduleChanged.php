<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A job's schedule changed — a task was created/updated/completed/delayed/
 * moved/deleted/(re)assigned, a dependency changed, or the plan's own
 * window/working-week/status was edited.
 *
 * One event for all of the above rather than one per task action: every one
 * of them ends at the same place (`JobTaskController::settle()` or
 * `JobScheduleController::update()`), and a listening Schedule screen reacts
 * to "something about this schedule changed" the same way regardless of
 * which action caused it — by re-fetching its own props. `type` is carried
 * only for what an activity feed might want to say about it, not for the
 * frontend to branch on.
 */
class ScheduleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $jobId,
        public readonly string $type,
        public readonly string $description,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("job.{$this->jobId}")];
    }

    public function broadcastAs(): string
    {
        return 'schedule.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->jobId,
            'type' => $this->type,
            'description' => $this->description,
        ];
    }
}
