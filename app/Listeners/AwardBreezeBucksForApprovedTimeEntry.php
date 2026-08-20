<?php

namespace App\Listeners;

use App\Events\TimeEntryApproved;
use App\Models\RewardRule;
use App\Services\BreezeBucks\RewardRuleService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Awards only on approval — never on timer-start, draft, or submission —
 * since `TimeEntryApproved` is only ever dispatched from the manager-gated
 * `TimeEntryController::approve()` action, after the DB transaction commits.
 */
class AwardBreezeBucksForApprovedTimeEntry implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RewardRuleService $rewards) {}

    public function handle(TimeEntryApproved $event): void
    {
        if ($event->entry->user) {
            $this->rewards->award(
                $event->entry->user,
                RewardRule::TIME_ENTRY_APPROVED,
                'time_entry',
                $event->entry->id,
            );
        }
    }
}
