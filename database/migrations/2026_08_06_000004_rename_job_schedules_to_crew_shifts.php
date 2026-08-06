<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frees the name `job_schedules` for the job's schedule itself.
 *
 * That table has only ever held crew shifts — one row per crew, per job, per day —
 * which is a different thing from "the schedule this job runs to": its dates,
 * working week, milestones and progress. With tasks and dependencies hanging off
 * the schedule, the two need separate names or every relationship reads ambiguously.
 *
 * A rename, so no row is touched and no data is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('job_schedules', 'crew_shifts');

        /*
         * Foreign-key constraint names are global to the schema in MySQL, and a
         * table rename does not touch them — so `crew_shifts` would still be
         * holding `job_schedules_job_id_foreign`, and the new `job_schedules`
         * table could never create its own. Re-pointed here so each table owns
         * names that match it.
         */
        Schema::table('crew_shifts', function (Blueprint $table) {
            $table->dropForeign('job_schedules_job_id_foreign');
            $table->dropForeign('job_schedules_team_member_id_foreign');
            $table->dropForeign('job_schedules_created_by_foreign');

            $table->foreign('job_id')->references('id')->on('work_jobs')->cascadeOnDelete();
            $table->foreign('team_member_id')->references('id')->on('team_members')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_shifts', function (Blueprint $table) {
            $table->dropForeign(['job_id']);
            $table->dropForeign(['team_member_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::rename('crew_shifts', 'job_schedules');

        Schema::table('job_schedules', function (Blueprint $table) {
            $table->foreign('job_id')->references('id')->on('work_jobs')->cascadeOnDelete();
            $table->foreign('team_member_id')->references('id')->on('team_members')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};
