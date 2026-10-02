<?php

namespace App\Notifications;

use App\Models\Job;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells a technician their phone checked them in or out on its own, at which job. */
class AttendanceRecorded extends Notification
{
    public const CHECK_IN = 'checkin';

    public const CHECK_OUT = 'checkout';

    public function __construct(
        public readonly Job $job,
        public readonly string $kind,
        public readonly \DateTimeInterface $at,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $in = $this->kind === self::CHECK_IN;

        return [
            'type' => $in ? 'attendance-checkin' : 'attendance-checkout',
            'title' => $in ? 'Checked in automatically' : 'Checked out automatically',
            'detail' => $in
                ? "You were checked in at {$this->job->name}."
                : "You were checked out of {$this->job->name}.",
            'link' => route('jobs.show', $this->job->id, absolute: false),
            'data' => ['jobId' => $this->job->id, 'at' => $this->at->format(DATE_ATOM)],
        ];
    }
}
