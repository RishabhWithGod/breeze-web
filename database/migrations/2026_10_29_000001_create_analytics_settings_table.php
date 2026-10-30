<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The thresholds the Analytics alerts are judged against — one row, editable
 * by a manager. `AnalyticsSetting::current()` is the single accessor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_settings', function (Blueprint $table) {
            $table->id();
            /* A job whose margin falls below this percentage is flagged. */
            $table->decimal('low_margin_pct', 5, 2)->default(15);
            /* Hours used as a percentage of the estimate before a job is flagged as nearing budget. */
            $table->decimal('hours_warning_pct', 5, 2)->default(90);
            /* A timer still running after this many hours is treated as a missing check-out. */
            $table->unsignedSmallInteger('stale_timer_hours')->default(12);
            /* Days past the due date before an unpaid invoice counts as overdue. */
            $table->unsignedSmallInteger('overdue_grace_days')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_settings');
    }
};
