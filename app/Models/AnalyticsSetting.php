<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The thresholds the Analytics alerts are judged against. One row, always —
 * `current()` creates it with the column defaults the first time it is asked.
 */
class AnalyticsSetting extends Model
{
    protected $fillable = [
        'low_margin_pct',
        'hours_warning_pct',
        'stale_timer_hours',
        'overdue_grace_days',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'low_margin_pct' => 'float',
            'hours_warning_pct' => 'float',
            'stale_timer_hours' => 'integer',
            'overdue_grace_days' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([])->refresh();
    }
}
