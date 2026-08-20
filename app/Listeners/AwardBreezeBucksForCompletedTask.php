<?php

namespace App\Listeners;

use App\Events\JobTaskCompleted;
use App\Models\RewardRule;
use App\Services\BreezeBucks\RewardRuleService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class AwardBreezeBucksForCompletedTask implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RewardRuleService $rewards) {}

    public function handle(JobTaskCompleted $event): void
    {
        $this->rewards->award(
            $event->completedBy,
            RewardRule::JOB_TASK_COMPLETED,
            'job_task',
            $event->task->id,
        );
    }
}
