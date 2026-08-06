<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The work a schedule is made of, plus the graph that orders it.
 *
 * Four tables, one idea: a task, who is on it, what it waits for, and what is
 * attached to it.
 *
 * `job_id` is carried on the task alongside `job_schedule_id`. It is denormalised
 * on purpose — every screen that lists tasks filters by job, and going through the
 * schedule for that adds a join to the hottest query in the module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('pending');
            $table->string('priority')->default('medium');
            $table->string('category')->nullable();

            $table->decimal('estimated_hours', 6, 2)->nullable();
            $table->decimal('actual_hours', 6, 2)->default(0);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            /* What the dates were before the last reschedule, so a delay is provable. */
            $table->date('baseline_ends_on')->nullable();

            $table->unsignedTinyInteger('completion_pct')->default(0);
            /* Manual order within the schedule; drag and drop rewrites it. */
            $table->unsignedInteger('position')->default(0);
            /* A milestone is a task with no duration — a date that must be met. */
            $table->boolean('is_milestone')->default(false);

            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'status']);
            $table->index(['job_schedule_id', 'position']);
            $table->index('ends_on');
            /*
             * A schedule cannot hold the same task twice. Enforced in the database
             * rather than only in the request, so an import or a duplicated job
             * cannot slip a repeat past it.
             */
            $table->unique(['job_schedule_id', 'title']);
        });

        Schema::create('job_task_dependencies', function (Blueprint $table) {
            $table->id();
            /* The task that waits. */
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            /* The task it waits on. */
            $table->foreignId('depends_on_id')->constrained('job_tasks')->cascadeOnDelete();
            /* finish_to_start | start_to_start | finish_to_finish */
            $table->string('type')->default('finish_to_start');
            /* Days of slack the successor must leave after the constraint is met. */
            $table->smallInteger('lag_days')->default(0);
            $table->timestamps();

            // One edge per pair per direction; the cycle check assumes no duplicates.
            $table->unique(['job_task_id', 'depends_on_id']);
        });

        Schema::create('job_task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('team_member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            /* The role this person holds on this task, from JobTask::ROLES. */
            $table->string('role');
            $table->timestamps();

            // The same person twice on one task, in one role, is a mistake not a fact.
            $table->unique(['job_task_id', 'team_member_id', 'role']);
        });

        Schema::create('job_task_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });

        Schema::create('job_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['job_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_task_comments');
        Schema::dropIfExists('job_task_attachments');
        Schema::dropIfExists('job_task_assignments');
        Schema::dropIfExists('job_task_dependencies');
        Schema::dropIfExists('job_tasks');
    }
};
