<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A job is worked by several foremen, each with their own subset of tasks
 * (see `Job::assignedForemen()`). Completion has to track the same way: one
 * foreman finishing and being approved must never depend on — or block —
 * another foreman's own tasks. This is the one row per (job, foreman) that
 * makes that possible; `work_jobs.ready_for_review_at`/`status` stay as the
 * whole-job signal, only flipped once every row here is approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_foreman_completions')) {
            return;
        }

        Schema::create('job_foreman_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->timestamp('ready_for_review_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'foreman_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_foreman_completions');
    }
};
