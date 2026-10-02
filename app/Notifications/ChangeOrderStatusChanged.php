<?php

namespace App\Notifications;

use App\Models\ChangeOrder;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells whoever should act next that a change order was submitted, approved or rejected. */
class ChangeOrderStatusChanged extends Notification
{
    public function __construct(public readonly ChangeOrder $changeOrder, public readonly string $status) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Change order {$this->changeOrder->label()} {$this->verb()}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->sentence())
            ->action('View the change order', $this->url());
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        return [
            'type' => "change-order-{$this->status}",
            'title' => 'Change Order '.ucfirst($this->status),
            'detail' => $this->sentence(),
            'link' => $this->url(false),
            'data' => [
                'changeOrderId' => $this->changeOrder->id,
                'jobId' => $this->changeOrder->job_id,
                'actions' => [['label' => 'View Change Order', 'href' => $this->url(false)]],
            ],
        ];
    }

    private function verb(): string
    {
        return $this->status === ChangeOrder::STATUS_SUBMITTED ? 'submitted for approval' : $this->status;
    }

    private function sentence(): string
    {
        $co = $this->changeOrder;

        return "Change order {$co->label()} (\"{$co->description}\") on {$co->job->name} was {$this->verb()}.";
    }

    private function url(bool $absolute = true): string
    {
        return route('change-orders.show', $this->changeOrder, $absolute);
    }
}
