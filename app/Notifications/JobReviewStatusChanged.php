<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells someone the crew's sign-off on a job moved — either the crew just
 * submitted it for review, or a supervisor sent it back by reopening a task.
 *
 * One notification with a reason rather than two near-identical classes, the
 * same shape as `TaskScheduleChanged`/`TimeEntryStatusChanged`: the link and
 * delivery are the same either way, only the sentence differs. App-bell only
 * — neither event is worth an email, both are things you'd only act on from
 * inside the app anyway.
 */
class JobReviewStatusChanged extends Notification
{
    public const READY_FOR_REVIEW = 'ready-for-review';

    public const REVERTED = 'reverted';

    public function __construct(
        public readonly Job $job,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('jobs.show', $this->job->id, absolute: false);

        return [
            'type' => "job-{$this->reason}",
            'title' => $this->headline(),
            'detail' => $this->sentence(),
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Job', 'href' => $link]]],
        ];
    }

    private function headline(): string
    {
        return match ($this->reason) {
            self::READY_FOR_REVIEW => 'Job ready for review',
            self::REVERTED => 'Job sent back to the crew',
            default => 'Job review status changed',
        };
    }

    private function sentence(): string
    {
        return match ($this->reason) {
            self::READY_FOR_REVIEW => "The crew marked \"{$this->job->name}\" done — review it and complete the job.",
            self::REVERTED => "A task was reopened on \"{$this->job->name}\" — it needs to be completed again before the job can move forward.",
            default => "\"{$this->job->name}\" changed.",
        };
    }
}
