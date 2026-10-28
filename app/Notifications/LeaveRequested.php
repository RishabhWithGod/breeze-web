<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells a manager that someone on the crew asked for time off and is waiting for an answer. */
class LeaveRequested extends Notification
{
    public function __construct(public readonly LeaveRequest $leave) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('leave.index', absolute: false);
        $days = $this->leave->days();

        return [
            'type' => 'leave-requested',
            'title' => 'Leave request',
            'detail' => "{$this->leave->user->name} asked for {$days} ".($days === 1 ? 'day' : 'days').' off ('
                .$this->leave->start_date->format('M j').($days > 1 ? ' – '.$this->leave->end_date->format('M j') : '').').',
            'link' => $link,
            'data' => ['actions' => [['label' => 'Review', 'href' => $link]]],
        ];
    }
}
