<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobTask;
use App\Models\TimerSession;
use App\Services\TimeTracking\TimerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The active timer: start, pause, resume, stop, discard.
 *
 * Every action redirects back, the same as every other mutation in this app —
 * the page that issued the request re-renders with fresh props (including the
 * current `activeTimer`), so there is no separate JSON contract to keep in
 * sync. A browser refresh gets the identical state through the same props,
 * which is what makes the timer survive a reload correctly.
 */
class TimerController extends Controller
{
    public function __construct(private readonly TimerService $timer) {}

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:work_jobs,id'],
            'job_task_id' => ['nullable', 'integer', 'exists:job_tasks,id'],
            'task_label' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billable' => ['nullable', 'boolean'],
        ]);

        $job = Job::findOrFail($data['job_id']);
        $task = ! empty($data['job_task_id']) ? JobTask::findOrFail($data['job_task_id']) : null;

        if ($task !== null && $task->job_id !== $job->id) {
            throw ValidationException::withMessages([
                'job_task_id' => 'That task does not belong to the selected job.',
            ]);
        }

        $this->timer->start(
            $request->user(),
            $job,
            $task,
            $data['task_label'] ?? null,
            $data['description'] ?? null,
            (bool) ($data['billable'] ?? true),
        );

        return back()->with('success', "Timer started on \"{$job->name}\".");
    }

    public function pause(Request $request): RedirectResponse
    {
        $session = $this->activeOrFail($request);
        $this->authorize('update', $session);

        $this->timer->pause($session);

        return back()->with('success', 'Timer paused.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $session = $this->activeOrFail($request);
        $this->authorize('update', $session);

        $this->timer->resume($session);

        return back()->with('success', 'Timer resumed.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $session = $this->activeOrFail($request);
        $this->authorize('update', $session);

        $entry = $this->timer->stop($session);

        return back()->with('success', "Logged {$entry->hours} hrs. Review and submit it when ready.");
    }

    public function discard(Request $request): RedirectResponse
    {
        $session = $this->activeOrFail($request);
        $this->authorize('update', $session);

        $this->timer->discard($session);

        return back()->with('warning', 'Timer discarded — nothing was logged.');
    }

    private function activeOrFail(Request $request): TimerSession
    {
        $session = $this->timer->active($request->user());

        if ($session === null) {
            throw ValidationException::withMessages([
                'timer' => 'There is no active timer.',
            ]);
        }

        return $session;
    }
}
