<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the invoice's creator it was sent or paid. */
class InvoiceStatusChanged extends Notification
{
    public const SENT = 'sent';

    public const PAID = 'paid';

    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $status,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->status === self::PAID
            ? ['mail', AppNotificationChannel::class]
            : [AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->invoice->invoice_number} was paid")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->invoice->invoice_number} for \${$this->amount()} was marked paid in full.")
            ->action('View the invoice', route('invoices.show', $this->invoice));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('invoices.show', $this->invoice, absolute: false);

        return [
            'type' => "invoice-{$this->status}",
            'title' => $this->status === self::PAID ? 'Invoice paid' : 'Invoice sent',
            'detail' => $this->status === self::PAID
                ? "{$this->invoice->invoice_number} for \${$this->amount()} was marked paid."
                : "{$this->invoice->invoice_number} for \${$this->amount()} was sent to {$this->invoice->client}.",
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Invoice', 'href' => $link]]],
        ];
    }

    private function amount(): string
    {
        return number_format((float) $this->invoice->total, 2);
    }
}
