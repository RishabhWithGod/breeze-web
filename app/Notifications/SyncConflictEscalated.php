<?php

namespace App\Notifications;

use App\Models\SyncConflict;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/** Tells a manager a technician sent a clashing change up for their decision. */
class SyncConflictEscalated extends Notification
{
    public function __construct(public readonly SyncConflict $conflict, public readonly string $by) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('sync-conflicts.index', absolute: false);

        return [
            'type' => 'sync-conflict',
            'title' => 'Sync conflict to review',
            'detail' => "{$this->by}'s change to “{$this->conflict->title}” clashes with an office edit.",
            'link' => $link,
            'data' => ['conflictId' => $this->conflict->id, 'actions' => [['label' => 'Review', 'href' => $link]]],
        ];
    }
}
