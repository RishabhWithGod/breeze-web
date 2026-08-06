<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Role-based staffing for a job: estimator, project manager, foreman,
 * electrician, reviewer.
 *
 * Rows are never deleted — releasing someone stamps `released_at`, so the
 * table doubles as the assignment history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('team_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('role');
            $table->string('name');
            $table->text('notes')->nullable();

            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_assignments');
    }
};
