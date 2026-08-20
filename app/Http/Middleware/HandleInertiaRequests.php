<?php

namespace App\Http\Middleware;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Props shared with every page.
     *
     * The app shell (profile menu, notification bell, flash alerts) renders on
     * every protected screen, so its data belongs here rather than in each
     * controller. Notifications are lazily evaluated so guest pages skip the
     * query entirely.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'appName' => config('app.name'),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'initials' => $user->initials,
                ] : null,
            ],

            // The bell shows a recent slice; the full, paginated, filterable
            // history lives at the Notification Center (`NotificationController::index`).
            'notifications' => fn () => $user
                ? NotificationResource::collection($user->appNotifications()->latest()->latest('id')->take(8)->get())->resolve()
                : [],

            'unreadNotificationCount' => fn () => $user
                ? $user->appNotifications()->unread()->count()
                : 0,

            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                /**
                 * Id of a just-deleted job, so the destination screen can offer
                 * Undo even when the delete happened somewhere else.
                 */
                'restoreJobId' => $request->session()->get('restore_job_id'),
                /**
                 * Plaintext 2FA recovery codes, shown exactly once right after
                 * they are generated — never persisted anywhere as plaintext,
                 * never re-shown on a later request.
                 */
                'recoveryCodes' => $request->session()->get('recoveryCodes'),
            ],
        ];
    }
}
