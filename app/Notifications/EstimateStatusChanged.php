<?php

namespace App\Notifications;

use App\Models\Estimate;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells whoever should act next that an estimate was approved or rejected by hand. */
class EstimateStatusChanged extends Notification
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly Estimate $estimate,
        public readonly string $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Estimate {$this->estimate->number} {$this->status}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Estimate {$this->estimate->number} for \"{$this->estimate->project}\" was {$this->status}.")
            ->action('View the estimate', route('estimates.show', $this->estimate));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $actions = [
            ['label' => 'View Estimate', 'href' => route('estimates.show', $this->estimate, absolute: false)],
        ];

        if ($this->status === self::APPROVED && $this->estimate->job_id) {
            $actions[] = ['label' => 'View Job', 'href' => route('jobs.show', $this->estimate->job_id, absolute: false)];
        }

        return [
            'type' => "estimate-{$this->status}",
            'title' => 'Estimate '.ucfirst($this->status),
            'detail' => "Estimate {$this->estimate->number} for \"{$this->estimate->project}\" was {$this->status}.",
            'link' => route('estimates.show', $this->estimate, absolute: false),
            'data' => ['actions' => $actions],
        ];
    }
}
