<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * A crew member (foreman, journeyman, apprentice) reported work from the
 * field — a task finished or moved along, a check-in or check-out — told to
 * the managers who run the job. See {@see \App\Services\Notifications\CrewWorkNotifier}.
 */
class CrewWorkUpdate extends Notification
{
    public function __construct(
        public readonly Job $job,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $type = 'job-crew-work',
    ) {}

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
            'type' => $this->type,
            'title' => $this->title,
            'detail' => $this->detail,
            'link' => $link,
            'data' => [
                'jobId' => $this->job->id,
                'actions' => [['label' => 'View Job', 'href' => $link]],
            ],
        ];
    }
}
