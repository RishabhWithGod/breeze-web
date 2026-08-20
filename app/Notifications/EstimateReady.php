<?php

namespace App\Notifications;

use App\Models\Estimate;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the manager when an estimate is generated from a takeoff. */
class EstimateReady extends Notification
{
    public function __construct(public readonly Estimate $estimate) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Estimate {$this->estimate->number} is ready")
            ->greeting("Hi {$notifiable->name},")
            ->line("Estimate {$this->estimate->number} was generated for “{$this->estimate->project}”.")
            ->line('Grand total: $'.number_format((float) $this->estimate->grand_total, 2))
            ->action('Review the estimate', route('estimates.show', $this->estimate))
            ->line('Every line, rate and percentage is still editable.');
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('estimates.show', $this->estimate, absolute: false);

        return [
            'type' => 'estimate-ready',
            'title' => "Estimate {$this->estimate->number} created",
            'detail' => $this->estimate->project.' — $'.number_format((float) $this->estimate->grand_total, 2),
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Estimate', 'href' => $link]]],
        ];
    }
}
