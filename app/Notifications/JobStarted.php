<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells a job's supervisor(s) the crew has actually begun — the foreman's
 * own "Start Job" tap (`Job::changeStatus('in-progress')`), fired by
 * `App\Listeners\NotifyOfJobStarted`.
 *
 * A gap this session's job-completion notification work otherwise left:
 * `JobReviewStatusChanged` covers "submitted for review" and "sent back",
 * `JobCompleted` covers the final sign-off — nothing told a supervisor the
 * job had even started. App-bell only: this is a heads-up to check in on,
 * not something worth an inbox interruption the way a review request or a
 * finished job is.
 */
class JobStarted extends Notification
{
    public function __construct(public readonly Job $job) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('jobs.show', $this->job->id, absolute: false);

        return [
            'type' => 'job-started',
            'title' => 'Job started',
            'detail' => "The crew started \"{$this->job->name}\".",
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Job', 'href' => $link]]],
        ];
    }
}
