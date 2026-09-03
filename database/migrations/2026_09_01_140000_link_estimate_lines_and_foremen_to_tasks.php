<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a task is made of, and who is running it.
 *
 * A job's tasks used to be free text typed from nothing, which left the
 * estimate and the plan as two unrelated lists — no way to tell whether the
 * work that was priced is the work that got scheduled.
 *
 * So a task is built from the estimate's own lines. `job_task_id` on an
 * estimate line is the whole rule: a line belongs to at most one task, which is
 * why it can be a column rather than a pivot, and why "already planned" is a
 * question with one honest answer. `nullOnDelete` frees the lines again when a
 * task is deleted — the work goes back to being unplanned, not lost.
 *
 * Foremen are a set: a task can need two, and a foreman runs several tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('estimate_items', 'job_task_id')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->foreignId('job_task_id')->nullable()->after('estimate_id')
                    ->constrained('job_tasks')->nullOnDelete();
            });
        }

        if (Schema::hasTable('job_task_foremen')) {
            return;
        }

        Schema::create('job_task_foremen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_task_id')->constrained('job_tasks')->cascadeOnDelete();
            $table->foreignId('foreman_id')->constrained('foremen')->cascadeOnDelete();
            $table->timestamps();

            // A foreman is on a task once, not twice.
            $table->unique(['job_task_id', 'foreman_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_task_foremen');

        if (Schema::hasColumn('estimate_items', 'job_task_id')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('job_task_id');
            });
        }
    }
};
