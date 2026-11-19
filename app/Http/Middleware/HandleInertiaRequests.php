<?php

namespace App\Http\Middleware;

use App\Http\Resources\NotificationResource;
use App\Models\ChangeOrder;
use App\Models\CompanyProfile;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Access\Permissions;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\TimeTracking\DailyTimesheetBuilder;
use App\Support\Ownership;
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
                    // The company's owner: every menu entry and action is theirs, whatever their title.
                    'isOwner' => $user->isCompanyOwner(),
                    'initials' => $user->initials,
                ] : null,
            ],

            // What this person's role may do (see config/permissions.php) — null when the matrix does not
            // govern them, which means everything. The menu shows nothing they cannot open.
            'permissions' => $user ? app(Permissions::class)->for($user) : null,

            // The company this person works for — its name and logo, for the header.
            'company' => fn () => $this->company($user),


            // The bell shows a recent slice; the full, paginated, filterable
            // history lives at the Notification Center (`NotificationController::index`).
            'notifications' => fn () => $user
                ? NotificationResource::collection($user->appNotifications()->latest()->latest('id')->take(8)->get())->resolve()
                : [],

            // What is waiting on this person, for the little counts on the sidebar rows: takeoffs ready for
            // review, change orders and time entries submitted for approval. Read-only; lazy like the bell.
            'sidebarCounts' => fn () => $user ? $this->sidebarCounts($user) : null,

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
                // Something the person's role does not allow: said once, wherever they are.
                'denied' => $request->session()->get('denied'),
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

    /** @return array{takeoff: int, changeOrders: int, timeEntries: int} */
    private function sidebarCounts(User $user): array
    {
        $takeoff = Project::query()
            ->whereIn('user_id', Ownership::userIds($user))
            ->whereIn('status', ['processing', 'completed', 'converted'])
            ->whereIn('review_status', ['pending', 'in-review'])
            ->count();

        $changeOrders = app(ChangeOrderAccess::class)
            ->visible(ChangeOrder::query(), $user)
            ->where('status', ChangeOrder::STATUS_SUBMITTED)
            ->count();

        $timeEntries = app(DailyTimesheetBuilder::class)
            ->entryQuery(['status' => TimeEntry::STATUS_SUBMITTED], $user->can('viewCrew', TimeEntry::class), $user->id)
            ->count();

        return ['takeoff' => $takeoff, 'changeOrders' => $changeOrders, 'timeEntries' => $timeEntries];
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

    /**
     * The name and logo of the company this person works for; null when they have
     * none. The logo is null too until one is uploaded — the header shows an icon then.
     *
     * @return array{id: int, name: string, logoUrl: ?string}|null
     */
    private function company(?User $user): ?array
    {
        $company = $user?->company_id === null ? null : CompanyProfile::query()->find($user->company_id);

        return $company === null ? null : ['id' => $company->id, 'name' => $company->name, 'logoUrl' => $company->logoUrl()];
    }
}
