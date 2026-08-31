<?php

namespace App\Services\Scheduling;

use App\Models\JobSchedule;
use App\Models\JobTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a schedule's progress actually is.
 *
 * Every figure here is derived from the tasks, not entered by anyone: a schedule
 * cannot report 80% while its tasks say otherwise. `JobSchedule::progress_pct` is a
 * cache of one of these numbers so lists can draw a bar without loading tasks, and
 * `refresh()` is its only writer.
 *
 * Progress is weighted by estimated hours rather than counted per task. Ten
 * two-hour tasks and one eighty-hour task are not the same amount of work, and
 * counting them equally would show a job as nearly done when the real work has not
 * started.
 */
class ScheduleProgress
{
    /**
     * @return array<string, mixed>
     */
    public function for(JobSchedule $schedule, ?Collection $tasks = null): array
    {
        $tasks = $tasks ?? $schedule->tasks()->get();
        $today = Carbon::today();

        $counted = $tasks->where('status', '!=', JobTask::STATUS_CANCELLED);
        $completed = $counted->where('status', JobTask::STATUS_COMPLETED);

        $late = $counted->filter(
            fn (JobTask $task) => $task->status === JobTask::STATUS_DELAYED || $task->isOverdue()
        );

        $workPct = $this->weightedPercentage($counted);
        $expectedPct = $this->expectedPercentage($schedule, $today);

        return [
            'tasks' => $tasks->count(),
            'counted' => $counted->count(),
            'completed' => $completed->count(),
            'remaining' => $counted->count() - $completed->count(),
            'inProgress' => $counted->where('status', JobTask::STATUS_IN_PROGRESS)->count(),
            'blocked' => $counted->where('status', JobTask::STATUS_BLOCKED)->count(),
            'delayed' => $late->count(),
            'cancelled' => $tasks->where('status', JobTask::STATUS_CANCELLED)->count(),
            'milestones' => $tasks->where('is_milestone', true)->count(),
            'milestonesMet' => $tasks->where('is_milestone', true)
                ->where('status', JobTask::STATUS_COMPLETED)->count(),

            /* Share of tasks finished — the headline the crew recognises. */
            'taskPct' => $this->percentage($completed->count(), $counted->count()),
            /* Share of estimated work finished, weighted by hours. */
            'workPct' => $workPct,
            /* Where the schedule *should* be today, by elapsed working days. */
            'expectedPct' => $expectedPct,

            'estimatedHours' => round((float) $counted->sum('estimated_hours'), 2),
            'actualHours' => round((float) $counted->sum('actual_hours'), 2),

            'startsOn' => $schedule->starts_on?->toDateString(),
            'endsOn' => $schedule->ends_on?->toDateString(),
            'durationDays' => $schedule->durationInWorkingDays(),
            'elapsedDays' => $this->elapsedWorkingDays($schedule, $today),
            'remainingDays' => $this->remainingWorkingDays($schedule, $today),
            'estimatedCompletion' => $this->estimatedCompletion($schedule, $counted, $today)?->toDateString(),
            'slippedDays' => $this->slippedDays($counted),
            /*
             * Behind means less work done than elapsed time accounts for. A tolerance
             * of five points keeps a schedule from flipping to "behind" on the first
             * morning of a task nobody has ticked off yet.
             */
            'isBehind' => $expectedPct - $workPct > 5,
            'variancePct' => $workPct - $expectedPct,
        ];
    }

    /**
     * Recomputes the cached percentage on the row.
     *
     * Weighted progress is what gets cached: it is the number that answers "how much
     * of this job is done", which is what a list is asking.
     */
    public function refresh(JobSchedule $schedule): int
    {
        $tasks = $schedule->tasks()->get();
        $counted = $tasks->where('status', '!=', JobTask::STATUS_CANCELLED);
        $percentage = $this->weightedPercentage($counted);

        $schedule->forceFill(['progress_pct' => $percentage])->saveQuietly();

        return $percentage;
    }

    /* ------------------------------------------------------------- internals */

    private function percentage(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    /**
     * Progress weighted by estimated hours, falling back to a flat count.
     *
     * A task's own `completion_pct` is used when it is part-done, so a half-finished
     * eighty-hour task contributes forty hours rather than nothing.
     *
     * @param  Collection<int, JobTask>  $tasks
     */
    private function weightedPercentage(Collection $tasks): int
    {
        if ($tasks->isEmpty()) {
            return 0;
        }

        $totalWeight = 0.0;
        $doneWeight = 0.0;

        foreach ($tasks as $task) {
            // An unestimated task still has to count for something, or a schedule of
            // them would report zero progress forever.
            $weight = max(0.5, (float) ($task->estimated_hours ?? 8));
            $share = $task->isComplete() ? 100 : min(100, max(0, $task->completion_pct));

            $totalWeight += $weight;
            $doneWeight += $weight * $share / 100;
        }

        return $totalWeight > 0 ? (int) round($doneWeight / $totalWeight * 100) : 0;
    }

    /** Where the schedule should be by now, on elapsed working days alone. */
    private function expectedPercentage(JobSchedule $schedule, Carbon $today): int
    {
        $duration = $schedule->durationInWorkingDays();

        if ($duration <= 0) {
            return 0;
        }

        return min(100, $this->percentage($this->elapsedWorkingDays($schedule, $today), $duration));
    }

    private function elapsedWorkingDays(JobSchedule $schedule, Carbon $today): int
    {
        if ($schedule->starts_on === null || $today->lt($schedule->starts_on)) {
            return 0;
        }

        $until = $schedule->ends_on !== null && $today->gt($schedule->ends_on)
            ? $schedule->ends_on
            : $today;

        return $schedule->countWorkingDays($schedule->starts_on, $until);
    }

    private function remainingWorkingDays(JobSchedule $schedule, Carbon $today): int
    {
        if ($schedule->ends_on === null || $today->gt($schedule->ends_on)) {
            return 0;
        }

        $from = $schedule->starts_on !== null && $today->lt($schedule->starts_on)
            ? $schedule->starts_on
            : $today;

        return $schedule->countWorkingDays($from, $schedule->ends_on);
    }

    /**
     * When the work will actually finish, at the rate it is going.
     *
     * Projected from hours completed per elapsed working day rather than from the
     * planned end date — that is the difference between a forecast and a repeat of
     * the plan. Falls back to the planned date when there is nothing to project from.
     *
     * @param  Collection<int, JobTask>  $tasks
     */
    private function estimatedCompletion(JobSchedule $schedule, Collection $tasks, Carbon $today): ?Carbon
    {
        if ($tasks->isEmpty()) {
            return $schedule->ends_on;
        }

        $latestTaskEnd = $tasks->whereNotNull('ends_on')->max('ends_on');
        $planned = $schedule->ends_on ?? ($latestTaskEnd ? Carbon::parse($latestTaskEnd) : null);

        $elapsed = $this->elapsedWorkingDays($schedule, $today);
        $done = $this->weightedPercentage($tasks);

        // Not started, or nothing measurable yet: the plan is the best answer there is.
        if ($elapsed <= 0 || $done <= 0) {
            return $planned;
        }

        if ($done >= 100) {
            return $tasks->max('completed_at')
                ? Carbon::parse($tasks->max('completed_at'))->startOfDay()
                : $today;
        }

        // Days needed at the observed rate, for the share still outstanding.
        $daysPerPercent = $elapsed / $done;
        $remainingDays = (int) ceil((100 - $done) * $daysPerPercent);

        $projected = $schedule->addWorkingDays($today, $remainingDays);

        // Never forecast earlier than the plan when already behind on the calendar.
        return $planned !== null && $projected->lt($planned) && $done < $this->expectedPercentage($schedule, $today)
            ? $planned
            : $projected;
    }

    /**
     * Worst slippage against the baseline, in days.
     *
     * The worst single task rather than the sum: two tasks each a week late do not
     * make the job two weeks late, they make it a week late twice over.
     *
     * @param  Collection<int, JobTask>  $tasks
     */
    private function slippedDays(Collection $tasks): int
    {
        return (int) max(0, $tasks->map(fn (JobTask $task) => $task->slippedDays())->max() ?? 0);
    }
}
