<?php

namespace App\Notifications;

use App\Models\PaymentProcessor;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the other managers a payment processor was connected, disconnected, or failed a test — bell only, a heads-up not a decision. */
class PaymentProcessorStatusChanged extends Notification
{
    public const CONNECTED = 'connected';

    public const DISCONNECTED = 'disconnected';

    public const ERROR = 'error';

    public function __construct(
        public readonly PaymentProcessor $processor,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment processor {$this->reason}: {$this->processor->display_name}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->sentence())
            ->action('Review Payment Settings', route('settings.payment.index'));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('settings.payment.index', absolute: false);

        return [
            'type' => "payment-processor-{$this->reason}",
            'title' => "{$this->processor->display_name} {$this->reason}",
            'detail' => $this->sentence(),
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Payment Settings', 'href' => $link]]],
        ];
    }

    private function sentence(): string
    {
        return match ($this->reason) {
            self::CONNECTED => "{$this->processor->display_name} was connected as a payment processor.",
            self::DISCONNECTED => "{$this->processor->display_name} was disconnected as a payment processor.",
            self::ERROR => "{$this->processor->display_name} failed its connection test: {$this->processor->last_error}",
            default => "{$this->processor->display_name}'s status changed.",
        };
    }
}
