<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers a real, time-limited verification code by email. Mail-only —
 * a one-time code has no business sitting in the Notification Center.
 */
class SecurityOtpCode extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Always sent via `Notification::route('mail', ...)` — an anonymous
        // notifiable with no `name` property — so the greeting can't
        // personalize by name.
        return (new MailMessage)
            ->subject('Your Breeze AI verification code')
            ->greeting('Hi,')
            ->line($this->sentence())
            ->line("Verification code: {$this->code}")
            ->line('This code expires in 10 minutes. If you did not request this, you can ignore this email.');
    }

    private function sentence(): string
    {
        return match ($this->purpose) {
            'enable_2fa' => 'Use this code to finish turning on two-factor authentication.',
            'change_email' => 'Use this code to confirm your new email address.',
            'change_phone' => 'Use this code to confirm your new phone number.',
            'login_2fa' => 'Use this code to finish signing in.',
            'password_reset' => 'Use this code to reset your password.',
            default => 'Use this code to verify this action.',
        };
    }
}
