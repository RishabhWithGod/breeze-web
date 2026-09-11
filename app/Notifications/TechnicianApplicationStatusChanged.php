<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a technician their application was approved or rejected.
 *
 * Same shape as `TimeEntryStatusChanged`: one class with a reason rather
 * than two near-identical ones.
 */
class TechnicianApplicationStatusChanged extends Notification
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly User $technician,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hi {$notifiable->name},")
            ->line($this->sentence());
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        return [
            'type' => "technician-{$this->reason}",
            'title' => $this->headline(),
            'detail' => $this->sentence(),
            'link' => null,
        ];
    }

    private function headline(): string
    {
        return match ($this->reason) {
            self::APPROVED => 'Your account was approved',
            self::REJECTED => 'Your account application was rejected',
            default => 'Your account status changed',
        };
    }

    private function sentence(): string
    {
        return match ($this->reason) {
            self::APPROVED => 'A manager approved your technician account. You now have full access to your jobs.',
            self::REJECTED => 'A manager reviewed your technician application and it was not approved.',
            default => 'Your account status changed.',
        };
    }
}
