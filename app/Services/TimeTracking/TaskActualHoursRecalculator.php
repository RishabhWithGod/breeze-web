<?php

namespace App\Services\TimeTracking;

use App\Models\JobTask;
use App\Models\TimeEntry;

/**
 * Keeps `job_tasks.actual_hours` in step with approved time entries.
 *
 * That column used to be hand-typed by a PM; it is now a cache with exactly
 * one writer, the same idiom `ScheduleProgress::refresh()` uses for
 * `progress_pct`. Called after approve/reject/reopen — never anywhere else.
 */
class TaskActualHoursRecalculator
{
    public function refresh(JobTask $task): void
    {
        // Locked rows are superseded originals — their correction is what counts.
        $hours = (float) $task->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->sum('hours');

        $task->update(['actual_hours' => round($hours, 2)]);
    }
}
