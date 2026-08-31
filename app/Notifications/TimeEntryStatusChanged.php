<?php

namespace App\Notifications;

use App\Models\TimeEntry;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone their time entry moved — submitted, approved, or rejected.
 *
 * One notification with a reason rather than three near-identical classes,
 * the same shape as `TaskScheduleChanged`: the recipient, the link and the
 * delivery method are the same in every case, and only the sentence differs.
 */
class TimeEntryStatusChanged extends Notification
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly TimeEntry $entry,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // A submission is a heads-up for the approver's bell; a decision on
        // your own time is worth an email too.
        return $this->reason === self::SUBMITTED
            ? [AppNotificationChannel::class]
            : ['mail', AppNotificationChannel::class];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jobName = $this->entry->job?->name ?? 'a job';

        $message = (new MailMessage)
            ->subject("{$this->headline()} — {$jobName}")
            ->greeting("Hi {$notifiable->name},")
            ->line("{$this->headline()} on {$jobName}.")
            ->line("Date: {$this->entry->date->format('M j, Y')} · {$this->entry->hours} hrs");

        if ($this->reason === self::REJECTED && $this->entry->rejection_reason) {
            $message->line("Reason: {$this->entry->rejection_reason}");
        }

        return $message->action('Open Time Tracking', route('time-entries.index', absolute: true));
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('time-entries.index', absolute: false);

        return [
            'type' => "time-entry-{$this->reason}",
            'title' => $this->headline(),
            'detail' => $this->sentence(),
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Time Logs', 'href' => $link]]],
        ];
    }

    /* ------------------------------------------------------------- internals */

    private function headline(): string
    {
        return match ($this->reason) {
            self::SUBMITTED => 'Time entry submitted for approval',
            self::APPROVED => 'Time entry approved',
            self::REJECTED => 'Time entry rejected',
            default => 'Time entry updated',
        };
    }

    private function sentence(): string
    {
        $jobName = $this->entry->job?->name ?? 'a job';

        return match ($this->reason) {
            self::SUBMITTED => "{$this->entry->user?->name} submitted {$this->entry->hours} hrs on {$jobName}.",
            self::APPROVED => "Your {$this->entry->hours} hrs on {$jobName} was approved.",
            self::REJECTED => "Your {$this->entry->hours} hrs on {$jobName} was rejected.",
            default => 'A time entry changed.',
        };
    }
}
