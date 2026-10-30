<?php

namespace App\Events;

use App\Models\Job;
use App\Models\JobScopeChange;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A job's scope changed: its estimate was edited, a change order approved,
 * a cost logged, or an invoice adjusted.
 *
 * Broadcast on `job.{id}` (whoever has that job open) and on the company's
 * channel (the Analytics dashboard and lists, which span jobs). The payload
 * is a nudge — which job, which version, who, from where — with no dollar
 * figures: a screen that hears it re-fetches over the authenticated,
 * per-role-redacted HTTP/API response, exactly like the other job events.
 */
class JobScopeChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Job $job,
        public readonly JobScopeChange $change,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("job.{$this->job->id}")];

        $companyId = $this->job->user_id !== null ? User::whereKey($this->job->user_id)->value('company_id') : null;
        if ($companyId !== null) {
            $channels[] = new PrivateChannel("company.{$companyId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'job.scope-changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->job->id,
            'estimateId' => $this->change->estimate_id,
            'version' => $this->change->version,
            'kind' => $this->change->kind,
            'source' => $this->change->source,
            'summary' => $this->change->summary,
            'byUserId' => $this->change->user_id,
            'by' => $this->change->author?->name,
            'at' => $this->change->created_at?->toIso8601String(),
        ];
    }
}
