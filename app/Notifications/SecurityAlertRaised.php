<?php

namespace App\Notifications;

use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user about a security event on their own account. Always lands in
 * the bell; mail only goes out when the user actually turned mail on for
 * this event's category — the four rows in `security_notification_preferences`
 * are the only source of truth for that, never an assumption.
 */
class SecurityAlertRaised extends Notification
{
    /** @var array<string, string> */
    public const PREFERENCE_MAP = [
        SecurityEvent::LOGIN_SUCCESS => SecurityNotificationPreference::LOGIN_ATTEMPT,
        SecurityEvent::LOGIN_FAILED => SecurityNotificationPreference::LOGIN_ATTEMPT,
        SecurityEvent::PASSWORD_CHANGED => SecurityNotificationPreference::PASSWORD_CHANGED,
        SecurityEvent::PROFILE_UPDATED => SecurityNotificationPreference::PROFILE_UPDATED,
        SecurityEvent::NEW_DEVICE_LOGIN => SecurityNotificationPreference::NEW_DEVICE_LOGIN,
    ];

    public function __construct(
        public readonly SecurityEvent $event,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = [AppNotificationChannel::class];
        $category = self::PREFERENCE_MAP[$this->event->type] ?? null;

        if ($category === null) {
            // Not one of the four user-configurable categories (2FA
            // enabled/disabled, recovery codes, auth method) — always a
            // bell notification, since these are always security-relevant.
            return $channels;
        }

        $preference = SecurityNotificationPreference::query()
            ->where('user_id', $notifiable->getKey())
            ->where('event_type', $category)
            ->first();

        // SMS has no configured provider anywhere in this app — the
        // preference can be turned on, but there is no channel to actually
        // send through, so it is never added here.
        if ($preference?->email_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->event->label())
            ->greeting("Hi {$notifiable->name},")
            ->line($this->event->description)
            ->line("Time: {$this->event->occurred_at->toDayDateTimeString()}")
            ->when($this->event->ip_address, fn ($mail) => $mail->line("IP address: {$this->event->ip_address}"))
            ->action('Review Security Settings', route('security.index'));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('security.index', absolute: false);

        return [
            'type' => "security-{$this->event->type}",
            'title' => $this->event->label(),
            'detail' => $this->event->description,
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Security Settings', 'href' => $link]]],
        ];
    }
}
