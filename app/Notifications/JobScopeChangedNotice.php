<?php

namespace App\Notifications;

use App\Models\Job;
use App\Models\JobScopeChange;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells a job's manager that someone else changed its scope — the crew from
 * the field, or an adjustment on an invoice. In the inbox and on the phone;
 * deliberately without dollar figures, which the screen it opens shows to
 * whoever may see them.
 */
class JobScopeChangedNotice extends Notification
{
    public function __construct(public readonly Job $job, public readonly JobScopeChange $change) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('job-costing.show', $this->job, absolute: false);
        $by = $this->change->author?->name ?? 'Someone';
        $from = match ($this->change->source) {
            'field' => 'from the field',
            'mobile' => 'on the app',
            'invoice' => 'on an invoice',
            default => '',
        };

        return [
            'type' => 'estimate-changed',
            'title' => "{$this->job->name}: scope updated",
            'detail' => trim("{$by} {$from} — {$this->change->summary}"),
            'link' => $link,
            'data' => ['jobId' => $this->job->id, 'version' => $this->change->version, 'actions' => [['label' => 'View Job Costing', 'href' => $link]]],
        ];
    }
}
