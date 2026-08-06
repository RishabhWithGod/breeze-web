<?php

namespace App\Listeners;

use App\Events\ReviewFinalised;
use App\Models\JobAssignment;
use App\Notifications\ReviewCompleted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Notifies the estimator that a signed-off takeoff is waiting.
 *
 * The estimator is whoever holds that role on the job the takeoff produced; with
 * no job or no estimator yet, the run's owner is told instead so the handoff is
 * never silently dropped.
 */
class NotifyEstimatorOfReview implements ShouldHandleEventsAfterCommit
{
    public function handle(ReviewFinalised $event): void
    {
        $result = $event->result;

        $estimator = $result->workJob
            ?->activeAssignments()
            ->where('role', JobAssignment::ROLE_ESTIMATOR)
            ->with('user')
            ->first()
            ?->user;

        ($estimator ?? $result->project->user)?->notify(new ReviewCompleted($result));
    }
}
