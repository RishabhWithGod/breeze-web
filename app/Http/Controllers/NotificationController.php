<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Notification Center: every notification the app has ever written for
 * the signed-in user, filterable and paginated from the same `app_notifications`
 * table the header bell already reads — never a second feed to keep in sync.
 */
class NotificationController extends Controller
{
    private const TABS = ['all', 'unread', 'read'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'category' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tab = $filters['tab'] ?? 'all';
        $category = $filters['category'] ?? 'all';

        $base = fn () => $user->appNotifications()
            ->ofCategory($category);

        $tabQuery = match ($tab) {
            'unread' => $base()->unread(),
            'read' => $base()->read(),
            default => $base(),
        };

        // `id` breaks ties between rows written in the same second — `latest()`
        // alone isn't a stable sort when several notifications fire at once.
        $notifications = $tabQuery->latest()->latest('id')->paginate(10)->withQueryString();

        return Inertia::render('Notifications', [
            'notifications' => NotificationResource::collection($notifications),
            'filters' => [
                'tab' => $tab,
                'category' => $category,
            ],
            'tabCounts' => [
                'all' => $base()->count(),
                'unread' => $base()->unread()->count(),
                'read' => $base()->read()->count(),
            ],
            'categories' => $this->categoriesInUse($user),
        ]);
    }

    /**
     * Marks read and, in the same request, carries the user on to whichever
     * action they actually clicked — one atomic visit, so the browser never
     * juggles a mark-read call and a navigation as two separate requests.
     */
    public function markRead(Request $request, AppNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        $redirect = $request->string('redirect')->toString();

        // Only ever a same-app relative path — never an open redirect.
        if ($redirect !== '' && str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return redirect($redirect);
        }

        // No destination given (or it failed the same-app check) — the
        // Notification Center itself, not an arbitrary "previous page".
        return redirect()->route('notifications.index');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    /** Only the categories this user actually has notifications in — never an empty tab in the filter. */
    private function categoriesInUse(User $user): array
    {
        $types = $user->appNotifications()->distinct()->pluck('type');

        $categories = $types->map(fn (string $type) => AppNotification::categoryFor($type))->unique()->sort()->values();

        return $categories->map(fn (string $category) => [
            'value' => $category,
            'label' => AppNotification::CATEGORY_LABELS[$category] ?? $category,
        ])->all();
    }
}
