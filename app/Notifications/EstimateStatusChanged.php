<?php

namespace App\Notifications;

use App\Models\Estimate;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells whoever should act next that an estimate was approved, rejected or sent back for edits. */
class EstimateStatusChanged extends Notification
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Sent back to draft by a reviewer, with notes on what to change. */
    public const RETURNED = 'returned';

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
            ->subject("Estimate {$this->estimate->number} {$this->verb()}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Estimate {$this->estimate->number} for \"{$this->estimate->project}\" was {$this->verb()}.")
            ->action('View the estimate', $this->url());
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $actions = [
            ['label' => $this->status === self::RETURNED ? 'Edit Estimate' : 'View Estimate', 'href' => $this->url(absolute: false)],
        ];

        if ($this->status === self::APPROVED && $this->estimate->job_id) {
            $actions[] = ['label' => 'View Job', 'href' => route('jobs.show', $this->estimate->job_id, absolute: false)];
        }

        return [
            'type' => "estimate-{$this->status}",
            'title' => 'Estimate '.ucfirst($this->status),
            'detail' => "Estimate {$this->estimate->number} for \"{$this->estimate->project}\" was {$this->verb()}.",
            'link' => $this->url(absolute: false),
            'data' => ['actions' => $actions],
        ];
    }

    /** How the decision reads in a sentence. */
    private function verb(): string
    {
        return $this->status === self::RETURNED ? 'returned for edits' : $this->status;
    }

    /** Where to go: back to the builder when it was sent back, otherwise the estimate. */
    private function url(bool $absolute = true): string
    {
        return $this->status === self::RETURNED && $this->estimate->builder_managed
            ? route('estimate-builder.show', $this->estimate, absolute: $absolute)
            : route('estimates.show', $this->estimate, absolute: $absolute);
    }
}
