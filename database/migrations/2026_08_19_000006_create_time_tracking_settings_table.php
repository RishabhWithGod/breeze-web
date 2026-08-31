<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's overtime and default-rate rules — one row, admin-editable.
 *
 * Business rules the user asked not to hard-code: daily/weekly overtime
 * thresholds, the overtime multiplier, and whether weekend/holiday hours count
 * as overtime. `config/time_tracking.php` supplies the defaults a fresh row is
 * created with; this table is what an admin actually edits afterwards.
 * `TimeTrackingSetting::current()` is the single accessor — never read the
 * table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_tracking_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('regular_daily_hours', 4, 2)->default(8);
            $table->decimal('regular_weekly_hours', 5, 2)->default(40);
            $table->decimal('overtime_multiplier', 3, 2)->default(1.5);
            $table->boolean('weekend_overtime')->default(true);
            $table->boolean('holiday_overtime')->default(true);
            /* ISO dates (YYYY-MM-DD) treated as holidays for overtime purposes. */
            $table->json('holiday_dates')->nullable();
            $table->decimal('default_billable_rate', 8, 2)->nullable();
            $table->decimal('default_cost_rate', 8, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_tracking_settings');
    }
};
