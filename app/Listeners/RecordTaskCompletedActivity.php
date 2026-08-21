<?php

namespace App\Listeners;

use App\Events\JobTaskCompleted;
use App\Models\FeedItem;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class RecordTaskCompletedActivity implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly FeedItemRecorder $recorder) {}

    public function handle(JobTaskCompleted $event): void
    {
        $job = $event->task->job?->name ?? 'a job';

        $this->recorder->record(FeedItem::DASHBOARD_ACTIVITY, "\"{$event->task->title}\" completed on {$job}", 'briefcase', 'lilac');
    }
}
