<?php

namespace App\Notifications;

use App\Models\CrewShift;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells the people on a job that one of its crew shifts was moved or removed. */
class ScheduleShiftChanged extends Notification
{
    public const CHANGED = 'changed';

    public const CANCELLED = 'cancelled';

    public function __construct(
        public readonly CrewShift $shift,
        public readonly string $reason = self::CHANGED,
        /** The day it was on, for a shift that has since been removed. */
        public readonly ?string $when = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $job = $this->shift->job?->name ?? 'a job';
        $cancelled = $this->reason === self::CANCELLED;
        $when = $this->when ?? $this->shift->scheduled_date?->format('D, M j').' at '.$this->shift->startLabel();

        return [
            'type' => $cancelled ? 'schedule-cancelled' : 'schedule-changed',
            'title' => $cancelled ? 'Shift cancelled' : 'Schedule changed',
            'detail' => $cancelled
                ? "Your shift on {$job} ({$when}) was removed from the schedule."
                : "Your shift on {$job} is now {$when}.",
            'link' => null,
            'data' => ['shiftId' => $this->shift->id, 'jobId' => $this->shift->job_id],
        ];
    }
}
