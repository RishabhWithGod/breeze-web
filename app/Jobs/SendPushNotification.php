<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Services\Push\FcmClient;
use App\Support\NotificationTarget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pushes one inbox notification to every phone its user is signed in on. */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public readonly int $notificationId) {}

    public function handle(FcmClient $fcm): void
    {
        if (! $fcm->enabled()) {
            return;
        }

        $notification = AppNotification::find($this->notificationId);
        if (! $notification) {
            return;
        }

        $devices = $notification->user?->deviceTokens ?? collect();
        if ($devices->isEmpty()) {
            return;
        }

        // The app opens the right screen from these — same keys its inbox reads.
        $data = ['notification_id' => (string) $notification->id, 'type' => (string) $notification->type];
        if ($notification->link) {
            $data['link'] = (string) $notification->link;
        }
        // Where the app should land when this is tapped — the same mapping the inbox uses.
        $first = $notification->link ?: ((array) $notification->data)['actions'][0]['href'] ?? null;
        $target = NotificationTarget::for($first);
        if ($target !== null) {
            $data['target_screen'] = $target['screen'];
            if ($target['id'] !== null) {
                $data['target_id'] = (string) $target['id'];
            }
        }
        foreach ((array) $notification->data as $key => $value) {
            if (is_scalar($value) && ! isset($data[$key])) {
                $data[$key] = (string) $value;
            }
        }

        $unread = AppNotification::where('user_id', $notification->user_id)->whereNull('read_at')->count();

        foreach ($devices as $device) {
            $fcm->send($device, $notification->title, (string) $notification->detail, $data, $unread);
            // A token FCM reported dead was just deleted — saving it again would bring it back.
            if ($device->exists) {
                $device->forceFill(['last_used_at' => now()])->save();
            }
        }
    }
}
