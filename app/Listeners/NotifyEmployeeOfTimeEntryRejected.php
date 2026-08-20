<?php

namespace App\Listeners;

use App\Events\TimeEntryRejected;
use App\Notifications\TimeEntryStatusChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class NotifyEmployeeOfTimeEntryRejected implements ShouldHandleEventsAfterCommit
{
    public function handle(TimeEntryRejected $event): void
    {
        $event->entry->user?->notify(new TimeEntryStatusChanged($event->entry, TimeEntryStatusChanged::REJECTED));
    }
}
