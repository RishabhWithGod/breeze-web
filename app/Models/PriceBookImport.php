<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One estimating workbook, as it was priced.
 *
 * Kept beside the rates it produced so any figure this system quotes can be
 * traced to the bid it came off — which job, at what labour rates, to what
 * total. A rate with no provenance is a guess with a decimal point.
 */
class PriceBookImport extends Model
{
    protected $fillable = [
        'file_name', 'file_hash', 'project_name',
        'material_cost', 'labor_cost', 'material_tax', 'total_cost', 'base_bid_price',
        'material_tax_pct', 'overhead_pct', 'profit_pct',
        'electrician_rate', 'supervisor_rate', 'unskilled_rate', 'composite_labor_rate',
        'total_manhours', 'line_count', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'material_tax' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'base_bid_price' => 'decimal:2',
            'total_manhours' => 'decimal:3',
        ];
    }

    /** @return HasMany<PriceBookLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PriceBookLine::class);
    }
}
