<?php

namespace App\Listeners;

use App\Events\TimeEntryApproved;
use App\Notifications\TimeEntryStatusChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class NotifyEmployeeOfTimeEntryApproved implements ShouldHandleEventsAfterCommit
{
    public function handle(TimeEntryApproved $event): void
    {
        $event->entry->user?->notify(new TimeEntryStatusChanged($event->entry, TimeEntryStatusChanged::APPROVED));
    }
}
