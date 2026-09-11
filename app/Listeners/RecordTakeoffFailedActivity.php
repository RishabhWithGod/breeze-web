<?php

namespace App\Listeners;

use App\Events\TakeoffFailed;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordTakeoffFailedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(TakeoffFailed $event): void
    {
        $name = $event->aiJob->project?->name ?? 'a project';
        $owner = $event->aiJob->project?->user;

        if ($owner) {
            $this->recorder->record($owner, FeedItem::DASHBOARD_ACTIVITY, "AI Takeoff failed for {$name}", 'triangle-alert', 'lilac');
        }
    }
}
