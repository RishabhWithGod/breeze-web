<?php

namespace App\Listeners;

use App\Events\TakeoffProcessed;
use App\Notifications\TakeoffReadyForReview;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/** Tells whoever uploaded the drawing that their takeoff is reviewable. */
class NotifyReviewerOfTakeoff implements ShouldHandleEventsAfterCommit
{
    public function handle(TakeoffProcessed $event): void
    {
        $owner = $event->result->project->user;

        $owner?->notify(new TakeoffReadyForReview($event->result));
    }
}
