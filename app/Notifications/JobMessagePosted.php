<?php

namespace App\Notifications;

use App\Models\Job;
use App\Models\JobMessage;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Someone wrote in a job's conversation — told to everyone else on the job.
 * See {@see \App\Services\Notifications\JobMessageNotifier}.
 */
class JobMessagePosted extends Notification
{
    public function __construct(
        public readonly Job $job,
        public readonly JobMessage $message,
        public readonly string $senderName,
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
            'type' => 'job-message',
            'title' => "New message — {$this->job->name}",
            'detail' => "{$this->senderName}: ".Str::limit(trim($this->message->body), 140),
            'link' => $link,
            'data' => [
                'jobId' => $this->job->id,
                'messageId' => $this->message->id,
                'actions' => [['label' => 'View Job', 'href' => $link]],
            ],
        ];
    }
}
