<?php

namespace App\Listeners;

use App\Events\TakeoffFailed;
use App\Models\AppNotification;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * A failed run is surfaced in the bell menu as well as on the processing screen,
 * so it is not missed by someone who navigated away.
 */
class NotifyOwnerOfFailure implements ShouldHandleEventsAfterCommit
{
    public function handle(TakeoffFailed $event): void
    {
        $project = $event->aiJob->project;

        AppNotification::create([
            'user_id' => $event->aiJob->user_id,
            'type' => 'takeoff-failed',
            'title' => 'AI takeoff failed',
            'detail' => "{$project->name}: {$event->reason}",
            'link' => route('processing.show', $project, absolute: false),
        ]);
    }
}
