<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which crew a job is for, and who supervises each task on it.
 *
 * A job is handed to a team, and the team is what narrows every later choice:
 * the foreman running a task and the supervisor over it are both picked from
 * that crew rather than from the whole register.
 *
 * Both nullable, and nothing is backfilled. Jobs raised before teams existed
 * belong to no crew, and guessing one from whoever happens to be on their tasks
 * would be inventing a chain of command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('work_jobs', 'team_id')) {
                // Disbanding a crew does not delete the work it was doing; the
                // job simply stops naming one.
                $table->foreignId('team_id')->nullable()->after('foreman_id')
                    ->constrained('teams')->nullOnDelete();
            }
        });

        Schema::table('job_tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('job_tasks', 'supervisor_id')) {
                /*
                 * Beside `foreman_id`, not instead of it: a task has someone
                 * running it and someone over it, and both are people on the
                 * register. Nulled rather than cascaded for the same reason
                 * `foreman_id` is — deleting a person must not erase the record
                 * of who ran the work.
                 */
                $table->foreignId('supervisor_id')->nullable()->after('foreman_id')
                    ->constrained('foremen')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('job_tasks', function (Blueprint $table) {
            if (Schema::hasColumn('job_tasks', 'supervisor_id')) {
                $table->dropForeign(['supervisor_id']);
                $table->dropColumn('supervisor_id');
            }
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            if (Schema::hasColumn('work_jobs', 'team_id')) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            }
        });
    }
};
