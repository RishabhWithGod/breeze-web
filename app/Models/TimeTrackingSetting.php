<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's overtime and default-rate rules. One row, always.
 *
 * `current()` is the only way this should ever be read — it creates the row
 * from `config('time_tracking.defaults')` the first time anything asks, so the
 * rest of the module never has to handle "no settings exist yet".
 */
class TimeTrackingSetting extends Model
{
    protected $fillable = [
        'regular_daily_hours',
        'regular_weekly_hours',
        'overtime_multiplier',
        'weekend_overtime',
        'holiday_overtime',
        'holiday_dates',
        'default_billable_rate',
        'default_cost_rate',
        'timezone',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'regular_daily_hours' => 'decimal:2',
            'regular_weekly_hours' => 'decimal:2',
            'overtime_multiplier' => 'decimal:2',
            'weekend_overtime' => 'boolean',
            'holiday_overtime' => 'boolean',
            'holiday_dates' => 'array',
            'default_billable_rate' => 'decimal:2',
            'default_cost_rate' => 'decimal:2',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], config('time_tracking.defaults'));
    }

    /** True when the given date falls on a configured holiday. */
    public function isHoliday(\DateTimeInterface $date): bool
    {
        return in_array($date->format('Y-m-d'), $this->holiday_dates ?? [], true);
    }
}
