<?php

namespace App\Services\Scheduling;

use App\Events\JobTaskCompleted;
use App\Events\ScheduleChanged;
use App\Models\EstimateItem;
use App\Models\JobTask;
use App\Models\User;
use App\Notifications\TaskScheduleChanged;
use Illuminate\Support\Collection;

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

    /**
     * Sets a task's status directly — the planner's override, independent of
     * the crew's own complete()/updateProgress() reporting. Mirrors exactly
     * what the web task-edit form's status field does: moving to `completed`
     * fills in the completion percentage and timestamp; moving off it (a
     * reopen) clears the timestamp so the task reads as genuinely open again,
     * not completed with the date quietly stale.
     */
    public function setStatus(JobTask $task, string $status): JobTask
    {
        $wasCompleted = $task->status === JobTask::STATUS_COMPLETED;
        $task->status = $status;

        if ($status === JobTask::STATUS_COMPLETED) {
            $task->completion_pct = 100;
            $task->completed_at ??= now();
        } elseif ($wasCompleted) {
            $task->completed_at = null;
            // Reopening a task undoes the crew's "everything is done" claim
            // — if a supervisor already signed off the job for review, send
            // it back so the crew has to close this again first.
            $task->job?->clearReadyForReview();
        }

        $task->save();

        $schedule = $task->schedule;
        $this->builder->markReady($schedule);
        $this->progress->refresh($schedule);

        $description = "Status of \"{$task->title}\" changed to {$status}";
        $task->job?->recordActivity('task_status_changed', $description);
        event(new ScheduleChanged($task->job_id, 'task_status_changed', $description));

        return $task;
    }

    /**
     * Recomputes a task's completion percentage from its checklist — the
     * estimate lines checked off on mobile — and moves its status to match:
     * forward to `in-progress` once something is checked, back to `ready`
     * if everything is unchecked again, and back out of `completed` if a
     * line on an already-completed task is unchecked, since it can no
     * longer honestly be called done. A `blocked`/`cancelled`/`delayed`
     * task is a planner's call, not something a checklist tick should
     * silently overrule. A task with no checklist at all has nothing to
     * derive a percentage from, so it is left untouched.
     *
     * Checking the last line is a real completion, not just a percentage
     * update — it gets the same activity entry, crew notification and
     * Breeze Bucks award {@see complete()} gives an explicit tap on the
     * task's own circle, via `$actor` (who checked the line).
     */
    public function syncFromChecklist(JobTask $task, User $actor): JobTask
    {
        $task->loadMissing('estimateItems');
        $laborItems = $this->laborItems($task);
        $total = $laborItems->count();
        if ($total === 0) {
            return $task;
        }

        $checked = $laborItems->whereNotNull('completed_at')->count();
        $pct = (int) round($checked / $total * 100);

        if ($pct === 100
            && ! in_array($task->status, [JobTask::STATUS_COMPLETED, JobTask::STATUS_CANCELLED], true)) {
            ['task' => $task] = $this->complete($task, $actor, []);

            return $task;
        }

        $wasCompleted = $task->status === JobTask::STATUS_COMPLETED;

        if (! $this->applyChecklistProgress($task)) {
            return $task;
        }

        if ($wasCompleted && $task->status !== JobTask::STATUS_COMPLETED) {
            // Unchecking a line dropped an already-completed task back
            // open — same "send it back to the crew" rule as `setStatus()`.
            $task->job?->clearReadyForReview();
        }

        $task->save();

        $schedule = $task->schedule;
        $this->builder->markReady($schedule);
        $this->progress->refresh($schedule);

        $description = "Checklist on \"{$task->title}\" updated to {$task->completion_pct}%";
        $task->job?->recordActivity('task_progress_updated', $description);
        event(new ScheduleChanged($task->job_id, 'task_progress_updated', $description));

        return $task;
    }

    /**
     * The same checklist-derived correction as {@see syncFromChecklist()},
     * but silent and read-time: a task whose percentage was never toggled
     * through the mobile checklist (an old demo/seeded value, or a line
     * checked before this feature computed anything) can carry a stored
     * `completion_pct` that no longer matches its actual checked/total
     * ratio. Every mobile task list/detail read calls this so what a
     * technician sees is always the real count, not a stale column — with
     * no activity entry or broadcast, since nobody actually did anything
     * just now; a fetch merely noticed the numbers disagreed.
     *
     * Requires `estimateItems` already eager-loaded — silently does nothing
     * otherwise, since a relation this reads cannot be trusted to lazy-load
     * correctly across the collections this is mapped over.
     */
    public function reconcileChecklistProgress(JobTask $task): JobTask
    {
        if (! $task->relationLoaded('estimateItems')) {
            return $task;
        }
        if ($this->applyChecklistProgress($task)) {
            $task->saveQuietly();
        }

        return $task;
    }

    /**
     * The math and status-transition rule {@see syncFromChecklist()} and
     * {@see reconcileChecklistProgress()} share. Mutates `$task` in place;
     * never saves. Requires `estimateItems` already loaded.
     *
     * @return bool whether anything on the task actually changed
     */
    private function applyChecklistProgress(JobTask $task): bool
    {
        $laborItems = $this->laborItems($task);
        $total = $laborItems->count();
        if ($total === 0) {
            return false;
        }

        $checked = $laborItems->whereNotNull('completed_at')->count();
        $pct = (int) round($checked / $total * 100);

        $changed = $task->completion_pct !== $pct;
        $task->completion_pct = $pct;

        if ($task->status === JobTask::STATUS_COMPLETED && $pct < 100) {
            $task->status = JobTask::STATUS_IN_PROGRESS;
            $task->completed_at = null;
            $changed = true;
        } elseif ($pct > 0 && in_array($task->status, [JobTask::STATUS_PENDING, JobTask::STATUS_READY], true)) {
            $task->status = JobTask::STATUS_IN_PROGRESS;
            $changed = true;
        } elseif ($pct === 0 && $task->status === JobTask::STATUS_IN_PROGRESS) {
            $task->status = JobTask::STATUS_READY;
            $changed = true;
        }

        return $changed;
    }

    /**
     * The lines a task's checklist actually derives its progress from —
     * labor only. A material line is never "done" the way a labor line is;
     * it rides along on the labor line that installs it
     * (`JobTaskSetupController::pairedMaterialLines()`) purely as
     * inventory info for the Materials screen, and was never meant to be
     * a separate checklist tick.
     */
    private function laborItems(JobTask $task): Collection
    {
        return $task->estimateItems->where('category', EstimateItem::CATEGORY_LABOR);
    }
}
