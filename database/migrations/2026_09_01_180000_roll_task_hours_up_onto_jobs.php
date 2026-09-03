<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A job's estimated hours are the sum of its tasks'.
 *
 * The chain runs estimate → task → job: the estimate prices the labour, each
 * task carries the hours of the lines it covers, and the job is what they add
 * up to. Jobs planned before that rule existed have tasks with hours and
 * nothing on the job itself, so the scheduling list showed them as "—".
 *
 * Only jobs with no figure of their own are touched — anything typed in by
 * hand is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hoursByJob = DB::table('job_tasks')
            ->whereNotNull('estimated_hours')
            ->groupBy('job_id')
            ->selectRaw('job_id, sum(estimated_hours) as hours')
            ->pluck('hours', 'job_id');

        foreach ($hoursByJob as $jobId => $hours) {
            if ((float) $hours <= 0) {
                continue;
            }

            DB::table('work_jobs')
                ->where('id', $jobId)
                ->whereNull('estimated_hours')
                ->update(['estimated_hours' => round((float) $hours, 2)]);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: which of these were blank before is not
        // recorded anywhere, so "undoing" it would be guessing.
    }
};
