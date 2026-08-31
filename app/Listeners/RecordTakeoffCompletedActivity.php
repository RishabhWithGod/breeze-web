<?php

namespace App\Listeners;

use App\Events\TakeoffProcessed;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordTakeoffCompletedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(TakeoffProcessed $event): void
    {
        $name = $event->result->project?->name ?? 'a project';

        $this->recorder->record(FeedItem::DASHBOARD_ACTIVITY, "AI Takeoff completed for {$name}", 'bot', 'butter');
        $this->recorder->record(FeedItem::HISTORY_ACTIVITY, "AI Takeoff completed for {$name}", 'bot', 'butter');
    }
}
