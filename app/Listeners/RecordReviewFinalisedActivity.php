<?php

namespace App\Listeners;

use App\Events\ReviewFinalised;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordReviewFinalisedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(ReviewFinalised $event): void
    {
        $name = $event->result->project?->name ?? 'a project';

        $this->recorder->record(FeedItem::DASHBOARD_ACTIVITY, "Review finalised for {$name}", 'file-text', 'lilac');
        $this->recorder->record(FeedItem::HISTORY_ACTIVITY, "Review finalised for {$name}", 'file-text', 'lilac');
    }
}
