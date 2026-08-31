<?php

namespace App\Listeners;

use App\Events\TimeEntryApproved;
use App\Services\JobCosting\JobCostOverrunNotifier;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Approving time is the moment a job's *actual* labor hours/cost can newly
 * cross its budget — no other action changes that number. Reuses the
 * existing `TimeEntryApproved` event rather than adding a second one just
 * for this.
 */
class NotifyManagersOfJobCostOverrun implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly JobCostOverrunNotifier $notifier) {}

    public function handle(TimeEntryApproved $event): void
    {
        if ($event->entry->job !== null) {
            $this->notifier->checkAndNotify($event->entry->job);
        }
    }
}
