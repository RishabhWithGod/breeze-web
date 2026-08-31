<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable line on an estimate. `total` is always quantity × unit cost, kept
 * in sync on save so the estimate totals can be summed in SQL.
 */
class EstimateItem extends Model
{
    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_FIXTURE = 'fixture';

    public const CATEGORY_LABOR = 'labor';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORIES = [
        self::CATEGORY_MATERIAL,
        self::CATEGORY_FIXTURE,
        self::CATEGORY_LABOR,
        self::CATEGORY_EQUIPMENT,
    ];

    public const CATEGORY_LABELS = [
        self::CATEGORY_MATERIAL => 'Materials',
        self::CATEGORY_FIXTURE => 'Fixtures',
        self::CATEGORY_LABOR => 'Labor',
        self::CATEGORY_EQUIPMENT => 'Equipment',
    ];

    protected $fillable = [
        'estimate_id',
        'final_symbol_id',
        'category',
        'description',
        'unit',
        'quantity',
        'unit_cost',
        'total',
        'source',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->total = round((float) $item->quantity * (float) $item->unit_cost, 2);
        });
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** @return BelongsTo<FinalSymbol, $this> */
    public function finalSymbol(): BelongsTo
    {
        return $this->belongsTo(FinalSymbol::class);
    }
}
