<?php

namespace App\Listeners;

use App\Events\EstimateGenerated;
use App\Models\JobAssignment;
use App\Notifications\EstimateReady;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Notifies the project manager that an estimate exists.
 *
 * Falls back to the takeoff owner when the job has no manager assigned yet.
 */
class NotifyManagerOfEstimate implements ShouldHandleEventsAfterCommit
{
    public function handle(EstimateGenerated $event): void
    {
        $estimate = $event->estimate;

        $manager = $estimate->job
            ?->activeAssignments()
            ->where('role', JobAssignment::ROLE_PROJECT_MANAGER)
            ->with('user')
            ->first()
            ?->user;

        ($manager ?? $estimate->takeoffProject?->user)?->notify(new EstimateReady($estimate));
    }
}
