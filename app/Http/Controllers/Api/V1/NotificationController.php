<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile app's notification center — the exact same `app_notifications`
 * table and `NotificationResource` the web bell/Notification Center use, so
 * a notification created by any event (Phase 8's realtime among them)
 * shows up identically on both surfaces. No push delivery is implemented
 * here — see docs/mobile-api.md for what that would require.
 */
class NotificationController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->appNotifications()
            ->latest()->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'notifications' => NotificationResource::collection($notifications->getCollection())->resolve($request),
            'unreadCount' => $request->user()->appNotifications()->unread()->count(),
            'meta' => [
                'currentPage' => $notifications->currentPage(),
                'lastPage' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $this->ok((new NotificationResource($notification))->resolve($request), 'Marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return $this->ok(null, 'All notifications marked as read.');
    }
}
