<?php

namespace App\Notifications\Channels;

use App\Events\NotificationCreated;
use App\Jobs\SendPushNotification;
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

        if (self::isSuppressed($notification)) {
            return null;
        }

        $payload = $notification->toAppNotification($notifiable);
        $userId = $notifiable->getKey();

        if (blank($payload['title'] ?? null) || blank($userId)) {
            return null;
        }

        $notification = AppNotification::create([
            'user_id' => $userId,
            'type' => $payload['type'] ?? 'general',
            'title' => $payload['title'],
            'detail' => $payload['detail'] ?? '',
            'link' => $payload['link'] ?? null,
            'data' => $payload['data'] ?? null,
        ]);

        event(new NotificationCreated($notification));

        // The same alert, to the user's phones. Queued so a slow FCM never delays the request.
        SendPushNotification::dispatch($notification->id)->afterCommit();

        return $notification;
    }

    /**
     * Notices the app does not raise: signing in and an estimate simply being
     * drafted are the person's own doing, so they are not told about them.
     */
    private static function isSuppressed(Notification $notification): bool
    {
        if ($notification instanceof \App\Notifications\EstimateReady) {
            return true;
        }

        return $notification instanceof \App\Notifications\SecurityAlertRaised
            && in_array($notification->event->type, [
                \App\Models\SecurityEvent::LOGIN_SUCCESS,
                \App\Models\SecurityEvent::NEW_DEVICE_LOGIN,
            ], true);
    }
}
