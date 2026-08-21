<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `work_jobs.foreman_id` was made nullable in a later migration (a job can
 * legitimately have no foreman), but its foreign key was never updated
 * from the original `cascadeOnDelete()` — so deleting a `Foreman` row
 * would hard-delete every job assigned to them, cascading further into
 * `time_entries`, `job_cost_entries`, and every other job-child table,
 * destroying payroll/audit history that those tables' own `SoftDeletes`
 * columns exist specifically to preserve. `nullOnDelete()` matches what
 * "foreman_id is nullable" already implies: removing a foreman clears the
 * reference, it does not take the job (and everything under it) with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropForeign(['foreman_id']);
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->foreign('foreman_id')->references('id')->on('foremen')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropForeign(['foreman_id']);
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->foreign('foreman_id')->references('id')->on('foremen')->cascadeOnDelete();
        });
    }
};
