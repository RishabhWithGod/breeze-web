<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One real, manually-recorded actual cost against a job — material,
 * equipment, or anything else that is not labor (labor's actual cost comes
 * from approved time entries instead; see `JobCostSummary`).
 */
class JobCostEntry extends Model
{
    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_MATERIAL,
        self::CATEGORY_EQUIPMENT,
        self::CATEGORY_OTHER,
    ];

    protected $fillable = [
        'job_id',
        'category',
        'description',
        'quantity',
        'unit_cost',
        'amount',
        'incurred_on',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'incurred_on' => 'date',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeIncurredBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('incurred_on', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('incurred_on', '<=', $to));
    }
}
