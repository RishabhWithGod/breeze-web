<?php

namespace App\Listeners;

use App\Events\TimeEntryApproved;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordTimeEntryApprovedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(TimeEntryApproved $event): void
    {
        $who = $event->entry->user?->name ?? 'Someone';
        $job = $event->entry->job?->name ?? 'a job';
        $owner = $event->entry->job?->owner;

        if ($owner) {
            $this->recorder->record($owner, FeedItem::DASHBOARD_ACTIVITY, "{$who}'s time entry on {$job} was approved", 'file-text', 'lilac');
        }
    }
}
