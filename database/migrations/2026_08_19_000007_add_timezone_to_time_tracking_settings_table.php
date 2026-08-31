<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business timezone every timer-derived entry's date/start/end time is
 * written in.
 *
 * `TimerService` computes elapsed time from `now()`, which Laravel keeps in
 * `config('app.timezone')` (UTC) for storage consistency across servers. A
 * manually typed entry's `start_time`/`end_time` are already local wall-clock
 * strings — whatever the person typed into a plain `<input type="time">` —
 * but a timer-derived entry needs an explicit conversion from that UTC
 * instant to this timezone, or its wall-clock time (and even its calendar
 * date, near midnight) comes out wrong for anyone outside UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_tracking_settings', function (Blueprint $table) {
            $table->string('timezone', 64)->default('UTC')->after('holiday_dates');
        });
    }

    public function down(): void
    {
        Schema::table('time_tracking_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
