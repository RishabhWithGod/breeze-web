<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\NotificationTarget;

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

    private const TABS = ['all', 'unread', 'read', 'approvals'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'category' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
        ]);
        $tab = $filters['tab'] ?? 'all';
        $category = $filters['category'] ?? 'all';
        $search = $filters['search'] ?? '';

        $base = fn () => $user->appNotifications()->ofCategory($category)->matching($search);
        $tabQuery = match ($tab) {
            'unread' => $base()->unread(),
            'read' => $base()->read(),
            'approvals' => $base()->approvals(),
            default => $base(),
        };

        $notifications = $tabQuery->latest()->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'notifications' => $notifications->getCollection()
                ->map(fn (AppNotification $n) => $this->present($n, $request))->all(),
            // Account-wide, whatever the filter — what the header bell shows.
            'unreadCount' => $user->appNotifications()->unread()->count(),
            'tabCounts' => [
                'all' => $base()->count(),
                'unread' => $base()->unread()->count(),
                'read' => $base()->read()->count(),
                'approvals' => $base()->approvals()->unread()->count(),
            ],
            'categories' => $this->categoriesInUse($user),
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

        return $this->ok($this->present($notification, $request), 'Marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return $this->ok(null, 'All notifications marked as read.');
    }

    /** The shared resource plus where each action leads inside the app. */
    private function present(AppNotification $notification, Request $request): array
    {
        $row = (new NotificationResource($notification))->resolve($request);
        $row['actions'] = array_map(fn (array $action) => $action + [
            'target' => NotificationTarget::for($action['href'] ?? null),
        ], $row['actions']);

        return $row;
    }

    /** Only the categories this person has notifications in. */
    private function categoriesInUse(\App\Models\User $user): array
    {
        return $user->appNotifications()->distinct()->pluck('type')
            ->map(fn (string $type) => AppNotification::categoryFor($type))
            ->unique()->sort()->values()
            ->map(fn (string $category) => [
                'value' => $category,
                'label' => AppNotification::CATEGORY_LABELS[$category] ?? $category,
            ])->all();
    }
}
