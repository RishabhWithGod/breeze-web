<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every estimate back the client it is for.
 *
 * `client_id` was never fillable, so every estimate raised from a takeoff, from
 * a job, or from the create form was written with the column left null while
 * its project was set. The edit screen then opened with no client chosen and
 * asked for one that could not be saved. The client is the project's client —
 * a project belongs to exactly one — so the missing values are derived rather
 * than guessed, and rows that already have one are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('estimates')
            ->join('projects', 'projects.id', '=', 'estimates.project_id')
            ->whereNull('estimates.client_id')
            ->whereNotNull('projects.client_id')
            ->update(['estimates.client_id' => DB::raw('projects.client_id')]);
    }

    /**
     * Nothing to undo: the values here are derived from the projects, so
     * dropping them again would only re-break the screens that read them.
     */
    public function down(): void {}
};
