<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced line from the engine's own bill of quantities.
 *
 * Kept exactly as returned. Where a line could be matched to a reviewed symbol,
 * `final_symbol_id` records it so the estimate can follow the *reviewed* quantity
 * instead of the engine's original count.
 */
class BoqLine extends Model
{
    protected $fillable = [
        'ai_result_id',
        'item',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'subtotal',
        'final_symbol_id',
        'matched_symbol',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    /** @return BelongsTo<FinalSymbol, $this> */
    public function finalSymbol(): BelongsTo
    {
        return $this->belongsTo(FinalSymbol::class);
    }
}
