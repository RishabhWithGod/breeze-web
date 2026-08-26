<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one active (or paused) timer a user has running, persisted server-side.
 *
 * A browser refresh must not lose or restart the clock, so nothing about "how
 * long has this been running" lives only in React state. `started_at` plus
 * `accumulated_seconds` (banked from any earlier pause/resume segments) is
 * everything needed to recompute the true elapsed time on any request —
 * `TimerService`/`TimerController::show()` is the only place that math happens.
 *
 * There is no partial unique index enforcing "one active timer per user" —
 * MySQL cannot express that as a plain unique constraint — so `TimerService`
 * enforces it itself inside a locked transaction. The `(user_id, status)` index
 * is what makes that check and the page-load read both cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timer_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('job_task_id')->nullable()->constrained('job_tasks')->nullOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('task_label')->nullable();
            $table->text('description')->nullable();

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('accumulated_seconds')->default(0);

            /* running | paused */
            $table->string('status')->default('running');
            $table->boolean('billable')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timer_sessions');
    }
};
