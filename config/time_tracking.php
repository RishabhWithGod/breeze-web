<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default business rules
    |--------------------------------------------------------------------------
    |
    | Only used to seed the one `time_tracking_settings` row the first time it
    | is read. After that, `TimeTrackingSetting::current()` is the source of
    | truth — an admin edits the row, not this file.
    |
    */

    'defaults' => [
        'regular_daily_hours' => (float) env('TIME_TRACKING_REGULAR_DAILY_HOURS', 8),
        'regular_weekly_hours' => (float) env('TIME_TRACKING_REGULAR_WEEKLY_HOURS', 40),
        'overtime_multiplier' => (float) env('TIME_TRACKING_OVERTIME_MULTIPLIER', 1.5),
        'weekend_overtime' => (bool) env('TIME_TRACKING_WEEKEND_OVERTIME', true),
        'holiday_overtime' => (bool) env('TIME_TRACKING_HOLIDAY_OVERTIME', true),
        'holiday_dates' => [],
        'default_billable_rate' => env('TIME_TRACKING_DEFAULT_BILLABLE_RATE'),
        'default_cost_rate' => env('TIME_TRACKING_DEFAULT_COST_RATE'),
        // A timer runs on `now()`, kept in `config('app.timezone')` (UTC) for
        // storage consistency — this is the timezone that UTC instant is
        // converted to before it becomes a `time_entries.date`/start/end time.
        'timezone' => env('TIME_TRACKING_TIMEZONE', 'UTC'),
    ],

    'per_page' => 15,

];
