<?php

namespace App\Listeners;

use App\Events\TakeoffProcessed;
use App\Models\RewardRule;
use App\Services\BreezeBucks\RewardRuleService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Rewards the project owner when the AI finishes producing a result —
 * `TakeoffProcessed`, not the later `ReviewFinalised` (a human finishing
 * review is a separate event, not "AI Takeoff completed").
 */
class AwardBreezeBucksForCompletedTakeoff implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RewardRuleService $rewards) {}

    public function handle(TakeoffProcessed $event): void
    {
        $owner = $event->result->project->user;

        if ($owner) {
            $this->rewards->award(
                $owner,
                RewardRule::AI_TAKEOFF_COMPLETED,
                'ai_result',
                $event->result->id,
            );
        }
    }
}
