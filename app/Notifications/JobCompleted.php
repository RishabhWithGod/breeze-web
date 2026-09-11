<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the crew and the office a job is genuinely finished — the
 * supervisor's own final sign-off (`Job::changeStatus('completed')`), fired
 * by `App\Listeners\NotifyOfJobCompletion`.
 *
 * Distinct from `JobReviewStatusChanged` — that one fires earlier, at the
 * crew submitting for review or a supervisor sending it back. This is the
 * very last step in that same workflow, and the one point after which
 * nothing about the job can change again.
 */
class JobCompleted extends Notification
{
    public function __construct(public readonly Job $job) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Job completed — {$this->job->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("\"{$this->job->name}\" has been reviewed and completed.")
            ->line('Nothing about it can be changed from here.')
            ->action('View Job', route('jobs.show', $this->job->id, absolute: true));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('jobs.show', $this->job->id, absolute: false);

        return [
            'type' => 'job-completed',
            'title' => 'Job completed',
            'detail' => "\"{$this->job->name}\" has been reviewed and completed.",
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Job', 'href' => $link]]],
        ];
    }
}
