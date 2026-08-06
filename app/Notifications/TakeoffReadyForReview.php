<?php

namespace App\Notifications;

use App\Models\AiResult;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when the AI response lands and someone needs to review it. */
class TakeoffReadyForReview extends Notification
{
    public function __construct(public readonly AiResult $result) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("AI takeoff ready for review: {$this->result->project->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("The AI takeoff for “{$this->result->project->name}” has finished.")
            ->line("{$this->result->detection_count} symbols were detected across {$this->result->page_count} pages.")
            ->action('Review the detections', route('reviews.show', $this->result))
            ->line('Nothing reaches an estimate until you approve it.');
    }

    /** @return array<string, string|null> */
    public function toAppNotification(object $notifiable): array
    {
        return [
            'type' => 'takeoff-ready',
            'title' => 'AI takeoff ready for review',
            'detail' => "{$this->result->detection_count} symbols detected in {$this->result->project->name}",
            'link' => route('reviews.show', $this->result, absolute: false),
        ];
    }
}
