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
    public const SOURCE_ESTIMATE = 'estimate';

    public const SOURCE_MANUAL = 'manual';

    /** Billed from an approved change order. */
    public const SOURCE_CHANGE_ORDER = 'change_order';

    public const CATEGORY_LABOR = 'labor';

    public const CATEGORY_MATERIAL = 'material';

    public const CATEGORY_EQUIPMENT = 'equipment';

    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'invoice_id',
        'change_order_id',
        'description',
        'source_category',
        /** `estimate` when copied off the estimate, `manual` when typed in. */
        'source',
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
