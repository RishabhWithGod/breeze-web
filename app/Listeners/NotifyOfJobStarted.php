<?php

namespace App\Listeners;

use App\Events\JobStatusChanged;
use App\Models\User;
use App\Notifications\JobStarted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Tells a job's supervisor(s) the crew has actually begun.
 *
 * The foreman who started it does not need telling — this is for whoever
 * oversees the job but was not the one on site tapping "Start Job".
 */
class NotifyOfJobStarted implements ShouldHandleEventsAfterCommit
{
    public function handle(JobStatusChanged $event): void
    {
        if ($event->to !== 'in-progress' || $event->from === 'in-progress') {
            return;
        }

        $job = $event->job;
        $actorId = Auth::id();

        $recipients = $job->notifiableSupervisors()
            ->reject(fn (User $user) => $user->id === $actorId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new JobStarted($job));
    }
}
