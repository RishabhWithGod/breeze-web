<?php

namespace App\Events;

use App\Models\TimerSession;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A user's timer started, paused, resumed, or stopped/discarded.
 *
 * Broadcast on that user's own channel — a timer is personal state kept in
 * sync across that person's own devices/tabs, not a job-wide announcement.
 * `ShouldDispatchAfterCommit` holds the broadcast until `TimerService`'s
 * write has actually committed, so a listening browser never sees a state
 * that a rolled-back transaction later undid.
 *
 * Deliberately carries no cost/rate figures — only what the existing
 * `activeTimer` shared Inertia prop already exposes.
 */
class TimerStateChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly ?TimerSession $session,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'timer.state-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        if ($this->session === null) {
            return ['action' => $this->action, 'timer' => null];
        }

        $this->session->loadMissing('job:id,name', 'jobTask:id,title');
        $service = app(\App\Services\TimeTracking\TimerService::class);

        return [
            'action' => $this->action,
            'timer' => [
                'id' => $this->session->id,
                'jobId' => $this->session->job_id,
                'jobName' => $this->session->job?->name ?? 'Unknown job',
                'taskLabel' => $this->session->jobTask?->title ?? $this->session->task_label,
                'status' => $this->session->status,
                'startedAt' => $this->session->started_at->toISOString(),
                'accumulatedSeconds' => $this->session->accumulated_seconds,
                'elapsedSeconds' => $service->elapsedSeconds($this->session),
                'billable' => $this->session->billable,
            ],
        ];
    }
}
