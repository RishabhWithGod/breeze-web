<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who an apprentice reports to on a job — the one relationship the crew
 * register doesn't already carry anywhere. `job_tasks.foreman_id`/
 * `supervisor_id` name who runs/oversees a *task*; this names who an
 * apprentice is under for a whole *job*, set once by a foreman rather than
 * inferred from task staffing.
 *
 * A job has at most one active assignment per apprentice — reassigning them
 * (to a different journeyman, or off the job) replaces the row rather than
 * adding another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_apprentice_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('journeyman_id')->constrained('foremen')->cascadeOnDelete();
            $table->foreignId('apprentice_id')->constrained('foremen')->cascadeOnDelete();
            // Who made the assignment — kept for the audit trail, not for
            // any authorization check, so a deleted account doesn't take the
            // assignment down with it.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'apprentice_id']);
            $table->index(['job_id', 'journeyman_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_apprentice_assignments');
    }
};
