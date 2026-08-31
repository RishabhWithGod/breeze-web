<?php

namespace App\Services\Scheduling;

use App\Events\JobTaskCompleted;
use App\Events\ScheduleChanged;
use App\Models\JobTask;
use App\Models\User;
use App\Notifications\TaskScheduleChanged;

/**
 * Marking a task complete — the one task action both the web schedule
 * screen and the mobile app perform, kept in exactly one place so a
 * completion from either client updates progress, records the activity,
 * broadcasts `ScheduleChanged`, notifies the crew, and fires
 * `JobTaskCompleted` (which awards Breeze Bucks and records dashboard
 * activity) identically.
 */
class JobTaskWorkflowService
{
    public function __construct(
        private readonly ScheduleProgress $progress,
        private readonly ScheduleBuilder $builder,
        private readonly ScheduleNotifier $notifier,
    ) {}

    /**
     * @param  array{actual_hours?: float|null, notes?: string|null}  $data
     * @return array{task: JobTask, unblocked: int}
     */
    public function complete(JobTask $task, User $actor, array $data): array
    {
        $task->forceFill([
            'status' => JobTask::STATUS_COMPLETED,
            'completion_pct' => 100,
            'completed_at' => now(),
            'actual_hours' => $data['actual_hours'] ?? $task->actual_hours,
            'notes' => $data['notes'] ?? $task->notes,
        ])->save();

        $schedule = $task->schedule;
        $unblocked = $this->builder->markReady($schedule);

        // Mirrors `JobTaskController::settle()`'s own trio exactly (it
        // re-runs `markReady()`, harmlessly idempotent, before refreshing
        // progress) so this extraction changes no observable behavior.
        $this->builder->markReady($schedule);
        $this->progress->refresh($schedule);

        $description = "Task completed: {$task->title}"
            .($unblocked > 0 ? ", unblocking {$unblocked} ".str('task')->plural($unblocked) : '');

        $task->job?->recordActivity('task_completed', $description);
        event(new ScheduleChanged($task->job_id, 'task_completed', $description));

        $this->notifier->taskChanged($task, TaskScheduleChanged::COMPLETED, except: $actor);
        JobTaskCompleted::dispatch($task, $actor);

        return ['task' => $task, 'unblocked' => $unblocked];
    }

    /**
     * A lighter update than `complete()` — the crew member doing the work
     * reporting how far along it is, without the completion timestamp,
     * successor-unblocking, or "task complete" notification that a real
     * completion carries.
     *
     * @param  array{completion_pct: int, actual_hours?: float|null, notes?: string|null}  $data
     */
    public function updateProgress(JobTask $task, array $data): JobTask
    {
        $task->fill([
            'completion_pct' => $data['completion_pct'],
            'actual_hours' => $data['actual_hours'] ?? $task->actual_hours,
            'notes' => $data['notes'] ?? $task->notes,
        ])->save();

        $schedule = $task->schedule;
        $this->builder->markReady($schedule);
        $this->progress->refresh($schedule);

        $description = "Progress on \"{$task->title}\" updated to {$data['completion_pct']}%";
        $task->job?->recordActivity('task_progress_updated', $description);
        event(new ScheduleChanged($task->job_id, 'task_progress_updated', $description));

        return $task;
    }
}
