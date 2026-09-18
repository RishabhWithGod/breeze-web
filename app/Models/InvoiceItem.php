<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One editable line on an invoice. `total` is always quantity × unit price,
 * kept in sync on save so the invoice's totals can be summed in SQL.
 */
class InvoiceItem extends Model
{
    /** The four categories `ActualCostInvoiceSync` keeps a job-linked invoice's own lines mirroring. */
    public const CATEGORY_LABOR = 'labor';

    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'invoice_id',
        'description',
        'source_category',
        'quantity',
        'unit_price',
        'total',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
