<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schedule a job runs to: its window, its working week, and its progress.
 *
 * One row per job — a job has one schedule, and tasks hang off it. The working week
 * and holidays live here rather than in config because they are negotiated per job:
 * a hospital retrofit works nights and weekends, an office fit-out does not, and
 * both need their durations counted against their own calendar.
 *
 * `progress_pct` is stored rather than derived on read because it is shown on lists
 * that must not load every task to render a bar. `ScheduleProgress` recomputes it
 * whenever a task changes, so it is a cache with exactly one writer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_schedules', function (Blueprint $table) {
            $table->id();
            // One schedule per job. Unique rather than a plain index, so a second
            // schedule cannot be created and silently ignored.
            $table->foreignId('job_id')->unique()->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            /* ISO day numbers the crew works: 1 = Monday … 7 = Sunday. */
            $table->json('working_days');
            $table->time('work_start_time')->default('08:00:00');
            $table->time('work_end_time')->default('16:30:00');
            /* Unpaid break, so a working day's hours are honest. */
            $table->unsignedSmallInteger('break_minutes')->default(30);
            $table->string('timezone')->default('UTC');
            /* Non-working dates: statutory holidays and site shutdowns. */
            $table->json('holidays')->nullable();

            $table->string('status')->default('draft');
            $table->unsignedTinyInteger('progress_pct')->default(0);
            $table->text('notes')->nullable();
            /* Stamped when the schedule is published, which is what notifies the crew. */
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_schedules');
    }
};
