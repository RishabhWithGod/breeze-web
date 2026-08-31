<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A real `app_notifications` row was written for a user — fired from
 * `AppNotificationChannel`, the single place every notification class in
 * the app already funnels through, so every notification type gets this for
 * free without each one dispatching its own broadcast.
 *
 * The payload is only ever what that row itself already contains, for the
 * one user it belongs to — `AppNotificationChannel::send()` is reached by
 * `Notifiable::notify()` calls that already decided who should be told what
 * (e.g. a job-cost-overrun notification is only ever sent to a manager), so
 * broadcasting it changes nothing about who is authorized to see it.
 */
class NotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AppNotification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->notification->user_id}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'type' => $this->notification->type,
            'title' => $this->notification->title,
            'detail' => $this->notification->detail,
            'link' => $this->notification->link,
            'createdAt' => $this->notification->created_at->toISOString(),
            'unreadCount' => AppNotification::where('user_id', $this->notification->user_id)
                ->whereNull('read_at')
                ->count(),
        ];
    }
}
