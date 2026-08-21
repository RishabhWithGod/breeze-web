<?php

namespace App\Events;

use App\Models\AiJob;
use App\Services\Ai\AiRunStatePresenter;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An AI takeoff run's status moved on — queued, uploading, processing,
 * succeeded, or failed.
 *
 * Broadcast on `project.{id}`, the same scope the Processing screen's own
 * long-poll already reads. The payload is `AiRunStatePresenter::present()` —
 * the exact shape the poll endpoint returns — so the browser can apply it
 * directly to its `run` state without a second, divergent representation of
 * "what a run's state looks like" ever existing on the frontend.
 */
class AiTakeoffStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AiJob $aiJob) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->aiJob->project_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ai-takeoff.status-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return AiRunStatePresenter::present($this->aiJob);
    }
}
