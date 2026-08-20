<?php

namespace App\Services\TimeTracking;

use App\Models\Job;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\TimeTrackingSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Start/pause/resume/stop/discard for the one timer a user may have running.
 *
 * Elapsed time is never trusted from the browser: `started_at` plus whatever
 * was already banked in `accumulated_seconds` is everything needed to
 * recompute the true duration on any request, so a refresh — or a request
 * from a different device — always shows the same number. There is no
 * partial unique index for "one active session per user" (MySQL cannot
 * express that), so it is enforced here, inside a locked transaction.
 */
class TimerService
{
    public function __construct(
        private readonly TeamMemberResolver $resolver,
        private readonly TimeEntryCalculator $calculator,
        private readonly LaborCostCalculator $costCalculator,
    ) {}

    public function active(User $user): ?TimerSession
    {
        return TimerSession::where('user_id', $user->id)->first();
    }

    public function start(
        User $user,
        Job $job,
        ?JobTask $task,
        ?string $taskLabel,
        ?string $description,
        bool $billable,
    ): TimerSession {
        return DB::transaction(function () use ($user, $job, $task, $taskLabel, $description, $billable) {
            $existing = TimerSession::where('user_id', $user->id)->lockForUpdate()->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'timer' => 'A timer is already running. Stop or discard it before starting another.',
                ]);
            }

            $teamMember = $this->resolver->resolveFor($user);

            return TimerSession::create([
                'user_id' => $user->id,
                'job_id' => $job->id,
                'job_task_id' => $task?->id,
                'team_member_id' => $teamMember->id,
                'task_label' => $task?->title ?? $taskLabel,
                'description' => $description,
                'started_at' => now(),
                'accumulated_seconds' => 0,
                'status' => TimerSession::STATUS_RUNNING,
                'billable' => $billable,
            ]);
        });
    }

    public function pause(TimerSession $session): TimerSession
    {
        if ($session->isPaused()) {
            return $session;
        }

        $session->update([
            'accumulated_seconds' => $session->accumulated_seconds + (int) $session->started_at->diffInSeconds(now()),
            'paused_at' => now(),
            'status' => TimerSession::STATUS_PAUSED,
        ]);

        return $session;
    }

    public function resume(TimerSession $session): TimerSession
    {
        if (! $session->isPaused()) {
            return $session;
        }

        $session->update([
            'started_at' => now(),
            'paused_at' => null,
            'status' => TimerSession::STATUS_RUNNING,
        ]);

        return $session;
    }

    /** Converts the session into a draft time entry and removes the session. */
    public function stop(TimerSession $session): TimeEntry
    {
        $seconds = $this->elapsedSeconds($session);
        $hours = round($seconds / 3600, 2);
        $this->calculator->assertHoursValid($hours);

        $settings = TimeTrackingSetting::current();

        // `started_at`/`now()` are UTC instants (`config('app.timezone')`) —
        // converted to the configured business timezone before becoming a
        // calendar date and wall-clock time, the same way a person typing
        // into a manual entry's `<input type="time">` already gets a local
        // wall-clock value with no conversion needed.
        $startedAtLocal = $session->started_at->copy()->timezone($settings->timezone);
        $endedAtLocal = now()->timezone($settings->timezone);

        $entry = new TimeEntry([
            'job_id' => $session->job_id,
            'job_task_id' => $session->job_task_id,
            'user_id' => $session->user_id,
            'team_member_id' => $session->team_member_id,
            'date' => $startedAtLocal->toDateString(),
            'start_time' => $startedAtLocal->toTimeString(),
            'end_time' => $endedAtLocal->toTimeString(),
            'break_minutes' => 0,
            'hours' => $hours,
            'task_label' => $session->task_label,
            'description' => $session->description,
            'billable' => $session->billable,
            'source' => TimeEntry::SOURCE_TIMER,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);
        $entry->save();
        $entry->load('teamMember');

        $overtime = (new OvertimeCalculator())->splitForEntry($entry, $settings);
        $this->costCalculator->apply($entry, $settings);
        $entry->fill($overtime)->save();

        $entry->recordInitialStatus();
        $entry->recordActivity('created', 'Time entry created by stopping a timer.', ['seconds' => $seconds]);

        $session->delete();

        return $entry;
    }

    public function discard(TimerSession $session): void
    {
        $session->delete();
    }

    public function elapsedSeconds(TimerSession $session): int
    {
        if ($session->isPaused()) {
            return $session->accumulated_seconds;
        }

        return $session->accumulated_seconds + (int) $session->started_at->diffInSeconds(now());
    }
}
