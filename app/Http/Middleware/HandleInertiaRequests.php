<?php

namespace App\Http\Middleware;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\Places\GooglePlaces;
use App\Services\Takeoff\TakeoffFlow;
use App\Services\TimeTracking\TimerService;
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

            /*
             * Whether the Site / Location field can look an address up. Shared
             * rather than passed per-screen so the field can say up front that
             * the lookup is off, instead of looking broken while it silently
             * returns nothing.
             */
            'addressLookupEnabled' => app(GooglePlaces::class)->configured(),

            /*
             * The takeoff someone is part-way through, when they are not on one
             * of its own screens. Shared globally because the point of it is to
             * be reachable from anywhere — the flow runs over days, and people
             * step out of it to look at something else.
             */
            'takeoffFlow' => fn () => app(TakeoffFlow::class)->current($request),

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

            // A running timer must be visible and controllable from any page,
            // not just the one it was started on — shared globally like the
            // bell, rather than fetched separately per page.
            'activeTimer' => fn () => $this->activeTimer($user),

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

    /** @return array<string, mixed>|null */
    private function activeTimer(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $service = app(TimerService::class);
        $session = $service->active($user);

        if ($session === null) {
            return null;
        }

        $session->loadMissing('job:id,name', 'jobTask:id,title');

        return [
            'id' => $session->id,
            'jobId' => $session->job_id,
            'jobName' => $session->job?->name ?? 'Unknown job',
            'taskLabel' => $session->jobTask?->title ?? $session->task_label,
            'status' => $session->status,
            'startedAt' => $session->started_at->toISOString(),
            'accumulatedSeconds' => $session->accumulated_seconds,
            'elapsedSeconds' => $service->elapsedSeconds($session),
            'billable' => $session->billable,
        ];
    }
}
