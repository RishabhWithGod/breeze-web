<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the Estimate Builder's worksheet: something being priced, with its
 * material and its labor side by side and a markup of its own.
 *
 * The figures are always worked out from the inputs, never typed in:
 * material total = quantity × unit price, labor total = hours × rate, and the
 * row's subtotal is those two with the markup on top.
 */
class EstimateBuilderLine extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TAKEOFF = 'takeoff';

    public const SOURCE_PRICE_LIST = 'price-list';

    protected $fillable = [
        'estimate_id', 'position', 'description', 'commodity', 'unit',
        'material_qty', 'material_unit_price', 'labor_hours', 'labor_rate', 'markup_pct',
        'source', 'source_estimate_item_id', 'price_book_item_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'material_qty' => 'decimal:4',
            'material_unit_price' => 'decimal:4',
            'labor_hours' => 'decimal:4',
            'labor_rate' => 'decimal:2',
            'markup_pct' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function materialTotal(): float
    {
        return round((float) $this->material_qty * (float) $this->material_unit_price, 2);
    }

    public function laborTotal(): float
    {
        return round((float) $this->labor_hours * (float) $this->labor_rate, 2);
    }

    /**
     * Material and labor with this row's markup on top. The markup is taken on each
     * side separately — as it is on the estimate's two lines — so the row and the
     * estimate always add up to the same cent.
     */
    public function subtotal(): float
    {
        $pct = (float) $this->markup_pct / 100;

        return round(
            $this->materialTotal() + round($this->materialTotal() * $pct, 2)
            + $this->laborTotal() + round($this->laborTotal() * $pct, 2),
            2,
        );
    }
}
