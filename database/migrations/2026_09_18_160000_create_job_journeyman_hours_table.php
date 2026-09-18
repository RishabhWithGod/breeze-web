<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager's direct, saved total hours for one person on one job — the
 * Billing screen's "Journeyman Hours" card no longer waits on the Time
 * Tracking approval workflow; a manager can set the real total straight
 * away, and it is this row, once it exists, that wins over whatever the
 * person's own time entries add up to (see `JobCostSummary::journeymanHours()`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_journeyman_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('work_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('hours', 8, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_journeyman_hours');
    }
};
