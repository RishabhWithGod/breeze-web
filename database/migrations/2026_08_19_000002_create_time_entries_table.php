<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A block of time worked, against a job and (optionally) one of its tasks.
 *
 * `job_id` is required and denormalised straight from the task when one is
 * picked — every list/report/filter in this module reads by job first, exactly
 * the reasoning `job_tasks.job_id` already documents for the same tradeoff.
 *
 * Two different identities are carried deliberately: `user_id` is who is signed
 * in and owns the entry (auth/audit); `team_member_id` is whose staffing record
 * the hours count against, so a schedule task's `job_task_assignments` can be
 * cross-referenced. They usually resolve to the same person, but the schema
 * does not assume that — see `TeamMemberResolver`.
 *
 * `hours` is the one number everything else is built from; `regular_hours`/
 * `overtime_hours` are `OvertimeCalculator`'s split of it, recomputed on save
 * rather than trusted from the client. `billable_rate`/`cost_rate`/`labor_cost`/
 * `billable_amount` are snapshotted at save time so a later rate change cannot
 * rewrite a past entry's cost.
 *
 * A `locked` entry is never edited in place — `corrects_id` points a correction
 * row at the original it amends, and the original stays exactly as approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('job_task_id')->nullable()->constrained('job_tasks')->nullOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('team_member_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('break_minutes')->default(0);

            $table->decimal('hours', 5, 2);
            $table->decimal('regular_hours', 5, 2)->default(0);
            $table->decimal('overtime_hours', 5, 2)->default(0);

            /* Free-text task label when the entry is not linked to a JobTask. */
            $table->string('task_label')->nullable();
            $table->text('description')->nullable();

            $table->boolean('billable')->default(true);
            $table->decimal('billable_rate', 8, 2)->nullable();
            $table->decimal('cost_rate', 8, 2)->nullable();
            $table->decimal('labor_cost', 10, 2)->nullable();
            $table->decimal('billable_amount', 10, 2)->nullable();

            /* manual | timer — how the entry came to exist. */
            $table->string('source')->default('manual');
            /* draft | submitted | approved | rejected | locked */
            $table->string('status')->default('draft');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            /* Set on a correction row; the locked entry it corrects is untouched. */
            $table->foreignId('corrects_id')->nullable()->constrained('time_entries')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->index('job_id');
            $table->index('user_id');
            $table->index('date');
            $table->index('status');
            $table->index('team_member_id');
            $table->index('job_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
