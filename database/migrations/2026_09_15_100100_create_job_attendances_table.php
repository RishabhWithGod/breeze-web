<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per technician, per job, per day — the server side of the mobile
 * app's GPS check-in/check-out feature (`AttendanceRepository` in
 * Breeze-Electric; see `docs/mobile-attendance-api-contract.md` there).
 *
 * A technician can check out for lunch and check back in later the same
 * day without a second row: `banked_seconds` carries the running total
 * across every earlier cycle already closed today, and the mobile app's
 * own `JobSiteAttendance.workingDuration` does the identical accumulation
 * on-device — this table is that same model, mirrored.
 *
 * Coordinates are nullable on both ends: a technician whose GPS failed
 * can still check in/out (the app sends `accuracy: -1` for that case,
 * which the API layer treats as "no usable fix", not an error) or check
 * in manually against a job with no site coordinates at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            /* checkedIn | checkedOut */
            $table->string('status');

            $table->timestamp('check_in_at')->nullable();
            $table->decimal('check_in_lat', 10, 7)->nullable();
            $table->decimal('check_in_lng', 10, 7)->nullable();
            $table->decimal('check_in_accuracy', 8, 2)->nullable();
            $table->decimal('check_in_distance_meters', 10, 2)->nullable();
            /* manual | automatic | photo */
            $table->string('check_in_method')->nullable();
            $table->string('check_in_photo_path')->nullable();

            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_out_lat', 10, 7)->nullable();
            $table->decimal('check_out_lng', 10, 7)->nullable();
            $table->decimal('check_out_accuracy', 8, 2)->nullable();
            $table->decimal('check_out_distance_meters', 10, 2)->nullable();
            $table->string('check_out_method')->nullable();

            /* Every cycle already closed today, not counting one still open. */
            $table->unsignedInteger('banked_seconds')->default(0);

            /* Client-generated, echoed back — not a hard-unique idempotency
               key: a same-day re-check-in intentionally reuses the row's own
               id as its next client_id, so uniqueness here would reject a
               second, genuine check-in later the same day. */
            $table->string('client_id')->nullable();

            $table->timestamps();

            /* One row per technician per job per day — a same-day
               re-check-in updates this row rather than creating another. */
            $table->unique(['job_id', 'user_id', 'date']);
            $table->index('user_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_attendances');
    }
};
