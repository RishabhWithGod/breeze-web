<?php

namespace App\Notifications;

use App\Models\AiResult;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the estimator once the final JSON has been generated. */
class ReviewCompleted extends Notification
{
    public function __construct(public readonly AiResult $result) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tally = $this->result->reviewTally();

        return (new MailMessage)
            ->subject("Takeoff signed off: {$this->result->project->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("The takeoff for “{$this->result->project->name}” has been reviewed and signed off.")
            ->line("{$tally['approved']} symbols approved, {$tally['rejected']} rejected, {$tally['modified']} modified.")
            ->action('Open the final symbol table', route('finals.show', $this->result))
            ->line('The approved quantities are ready to estimate.');
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $tally = $this->result->reviewTally();
        $link = route('finals.show', $this->result, absolute: false);

        return [
            'type' => 'review-completed',
            'title' => 'Takeoff review completed',
            'detail' => "{$this->result->project->name}: {$tally['approvedCount']} approved items ready to estimate",
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Final Takeoff', 'href' => $link]]],
        ];
    }
}
