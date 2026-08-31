<?php

namespace App\Services\Scheduling;

use App\Models\JobTask;
use App\Models\User;
use App\Notifications\TaskScheduleChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells the people on a task when it changes.
 *
 * Crew members and users are separate records — the crew list is who works here, the
 * user list is who signs in — so a notification can only reach someone who has both.
 * Matched on name, which is what the office types into each.
 *
 * Never fails the action that triggered it: a task is still complete even if the
 * mail server is down, so every failure is logged and swallowed.
 */
class ScheduleNotifier
{
    public function taskChanged(JobTask $task, string $reason, ?string $detail = null, ?User $except = null): int
    {
        $task->loadMissing(['assignments.member', 'job']);

        $names = $task->assignments
            ->map(fn ($assignment) => $assignment->member?->name)
            ->filter()
            ->map(fn (string $name) => mb_strtolower(trim($name)))
            ->unique();

        if ($names->isEmpty()) {
            return 0;
        }

        $recipients = User::query()
            ->whereIn(DB::raw('lower(trim(name))'), $names->all())
            // The person who made the change does not need telling about it.
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($recipients, new TaskScheduleChanged($task, $reason, $detail));
        } catch (Throwable $e) {
            Log::warning('Task notification could not be delivered', [
                'job_task_id' => $task->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        return $recipients->count();
    }
}
