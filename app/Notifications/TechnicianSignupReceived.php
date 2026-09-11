<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells a manager a new technician signed up from the mobile app and needs review. */
class TechnicianSignupReceived extends Notification
{
    public function __construct(public readonly User $technician) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        // Straight to Teams — that's where the pending-approval section
        // actually lives now, not the redirect-only `/technicians` address.
        $link = route('teams.index', absolute: false);

        return [
            'type' => 'technician-pending',
            'title' => 'New technician signup',
            'detail' => "{$this->technician->name} signed up and is waiting for approval.",
            'link' => $link,
            'data' => ['actions' => [['label' => 'Review', 'href' => $link]]],
        ];
    }
}
