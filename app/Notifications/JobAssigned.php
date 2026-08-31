<?php

namespace App\Notifications;

use App\Models\JobAssignment;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells someone they were just staffed onto a job in a given role. */
class JobAssigned extends Notification
{
    public function __construct(public readonly JobAssignment $assignment) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $job = $this->assignment->job;

        return (new MailMessage)
            ->subject("You've been assigned to {$job->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("You've been assigned as {$this->assignment->roleLabel()} on \"{$job->name}\".")
            ->action('View the job', route('jobs.show', $job))
            ->line('Everything about the job — schedule, team and documents — is on that page.');
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $job = $this->assignment->job;

        return [
            'type' => 'job-assigned',
            'title' => 'New job assignment',
            'detail' => "You've been assigned as {$this->assignment->roleLabel()} on \"{$job->name}\".",
            'link' => route('jobs.show', $job, absolute: false),
            'data' => [
                'actions' => [
                    ['label' => 'View Job', 'href' => route('jobs.show', $job, absolute: false)],
                ],
            ],
        ];
    }
}
