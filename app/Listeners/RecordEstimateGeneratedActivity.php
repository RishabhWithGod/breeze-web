<?php

namespace App\Listeners;

use App\Events\EstimateGenerated;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordEstimateGeneratedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(EstimateGenerated $event): void
    {
        $client = $event->estimate->client ?? 'a client';

        $this->recorder->record(FeedItem::DASHBOARD_ACTIVITY, "Estimate {$event->estimate->number} generated for {$client}", 'file-text', 'lilac');
    }
}
