<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crew scheduling: which crew is on which job, on which day, at what time.
 *
 * One row per shift rather than a pair of dates on the job, because that is what
 * the calendar actually shows — a job can run Monday to Friday with a different
 * crew on Thursday, and two crews can be on the same job on the same day. Dates on
 * `work_jobs` describe the job's span; these describe the work.
 *
 * `work_jobs` also gains the three fields the unassigned queue is ranked and
 * filtered by. They live on the job because they are true of the job whether or not
 * anything has been scheduled yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            // The crew lead shown on the calendar block. Nullable so a shift can be
            // pencilled in before anyone is named.
            $table->foreignId('team_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            /* Crew label — "Team A". Free text: crews are named by the office. */
            $table->string('crew')->default('Team A');
            $table->date('scheduled_date');
            $table->time('start_time')->default('08:00:00');
            $table->decimal('duration_hours', 5, 2)->default(8);
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            // The calendar always reads a date window, usually narrowed to one job.
            $table->index(['scheduled_date', 'start_time']);
            $table->index(['job_id', 'scheduled_date']);
        });

        Schema::table('work_jobs', function (Blueprint $table) {
            $table->string('priority')->default('medium')->after('status');
            $table->decimal('estimated_hours', 6, 2)->nullable()->after('priority');
            $table->json('required_skills')->nullable()->after('estimated_hours');

            // The unassigned queue is ordered by priority, so it is worth an index.
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::table('work_jobs', function (Blueprint $table) {
            $table->dropIndex(['priority']);
            $table->dropColumn(['priority', 'estimated_hours', 'required_skills']);
        });

        Schema::dropIfExists('job_schedules');
    }
};
