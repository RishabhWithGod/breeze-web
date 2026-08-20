<?php

namespace App\Notifications\Channels;

use App\Models\AppNotification;
use Illuminate\Notifications\Notification;

/**
 * Writes a notification into `app_notifications`, which backs the bell menu in
 * the app shell.
 *
 * A notification opts in by implementing `toAppNotification()` and returning
 * `['title' => ..., 'detail' => ..., 'link' => ..., 'type' => ..., 'data' => [...]]`.
 * `data.actions` (a list of `{label, href}`) is optional — a class that omits
 * it just gets one action in the Notification Center, generated from `link`.
 */
class AppNotificationChannel
{
    public function send(mixed $notifiable, Notification $notification): ?AppNotification
    {
        if (! method_exists($notification, 'toAppNotification')) {
            return null;
        }

        $payload = $notification->toAppNotification($notifiable);
        $userId = $notifiable->getKey();

        if (blank($payload['title'] ?? null) || blank($userId)) {
            return null;
        }

        return AppNotification::create([
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'general',
            'title' => $payload['title'],
            'detail' => $payload['detail'] ?? '',
            'link' => $payload['link'] ?? null,
            'data' => $payload['data'] ?? null,
        ]);
    }
}
