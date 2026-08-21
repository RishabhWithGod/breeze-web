<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimerSessionResource;
use App\Models\Job;
use App\Models\JobTask;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\TimeTracking\TimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The mobile timer — calling the exact same `TimerService` the web app's
 * `TimerController` calls. This is the critical piece of Phase 9: there is
 * no mobile-specific timer table or logic anywhere in this file. Every
 * state change (`start`/`pause`/`resume`/`stop`/`discard`) writes through
 * `TimerService`, which fires `TimerStateChanged` itself — the web app's
 * `TimerIndicator`, subscribed to `user.{id}`, sees a mobile-started timer
 * exactly as it would see one started from another browser tab.
 *
 * The service's own one-active-session-per-user guard (a locked
 * `SELECT ... FOR UPDATE` inside a transaction) is what actually prevents
 * two concurrent starts — from mobile, from web, or a mobile network's
 * retried request — from ever creating two sessions; this controller adds
 * no timer-specific idempotency logic of its own because none is needed.
 */
class TimerController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TimerService $timer,
        private readonly ElectricianJobAccess $access,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $session = $this->timer->active($request->user());

        return $this->ok($session ? (new TimerSessionResource($session))->resolve($request) : null);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:work_jobs,id'],
            'job_task_id' => ['nullable', 'integer', 'exists:job_tasks,id'],
            'task_label' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billable' => ['nullable', 'boolean'],
        ]);

        $job = Job::findOrFail($data['job_id']);
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $task = ! empty($data['job_task_id']) ? JobTask::findOrFail($data['job_task_id']) : null;

        if ($task !== null && $task->job_id !== $job->id) {
            throw ValidationException::withMessages([
                'job_task_id' => 'That task does not belong to the selected job.',
            ]);
        }

        $session = $this->timer->start(
            $request->user(),
            $job,
            $task,
            $data['task_label'] ?? null,
            $data['description'] ?? null,
            (bool) ($data['billable'] ?? true),
        );

        return $this->created((new TimerSessionResource($session))->resolve($request), 'Timer started.');
    }

    public function pause(Request $request): JsonResponse
    {
        $session = $this->timer->pause($this->activeOrFail($request));

        return $this->ok((new TimerSessionResource($session))->resolve($request), 'Timer paused.');
    }

    public function resume(Request $request): JsonResponse
    {
        $session = $this->timer->resume($this->activeOrFail($request));

        return $this->ok((new TimerSessionResource($session))->resolve($request), 'Timer resumed.');
    }

    public function stop(Request $request): JsonResponse
    {
        $entry = $this->timer->stop($this->activeOrFail($request));

        return $this->ok(['timeEntryId' => $entry->id, 'hours' => (float) $entry->hours], 'Timer stopped.');
    }

    public function discard(Request $request): JsonResponse
    {
        $this->timer->discard($this->activeOrFail($request));

        return $this->ok(null, 'Timer discarded — nothing was logged.');
    }

    private function activeOrFail(Request $request)
    {
        $session = $this->timer->active($request->user());

        if ($session === null) {
            throw ValidationException::withMessages([
                'timer' => 'There is no active timer.',
            ]);
        }

        // `TimerSessionPolicy` — the same ownership check the web
        // `TimerController` applies. `active()` already scopes to this
        // user, so this only ever matters if that ever changes.
        $this->authorize('update', $session);

        return $session;
    }
}
