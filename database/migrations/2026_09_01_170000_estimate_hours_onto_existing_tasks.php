<?php

use App\Models\EstimateItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the hours for tasks already built from estimate lines.
 *
 * A task's estimated hours are read off the labour lines it covers — the
 * estimate has already priced that work in hours, so asking for the number a
 * second time only invites the two to disagree. Tasks created before that rule
 * existed carry the lines but no hours, and the schedule showed them as "—".
 *
 * Only labour counts: adding a material line's 50 ft of cable to a task's hours
 * would be nonsense. A task whose lines carry no labour keeps its null, because
 * zero would claim the work is free.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hoursByTask = EstimateItem::query()
            ->whereNotNull('job_task_id')
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->groupBy('job_task_id')
            ->selectRaw('job_task_id, sum(quantity) as hours')
            ->pluck('hours', 'job_task_id');

        foreach ($hoursByTask as $taskId => $hours) {
            if ((float) $hours <= 0) {
                continue;
            }

            DB::table('job_tasks')
                ->where('id', $taskId)
                // Never over a figure someone typed in themselves.
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
