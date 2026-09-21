<?php

namespace App\Services\Billing;

use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * Copies an estimate's current items onto its draft invoice — every line,
 * not a collapsed summary — so the invoice starts as a real, editable copy
 * of the full estimate rather than something typed again by hand.
 *
 * A one-time copy, not an ongoing mirror: once on the invoice, a line is the
 * invoice's own — edited freely up to send, same as any other line — and is
 * never silently overwritten from the estimate again. A sent or paid invoice
 * is never touched here either; it has already gone to the client.
 */
class EstimateInvoiceSync
{
    /** A fixture prices like a material everywhere else in this app (see `Estimate::recalculateTotals()`) — kept that way here too. */
    private const CATEGORY_MAP = [
        EstimateItem::CATEGORY_MATERIAL => InvoiceItem::CATEGORY_MATERIAL,
        EstimateItem::CATEGORY_FIXTURE => InvoiceItem::CATEGORY_MATERIAL,
        EstimateItem::CATEGORY_EQUIPMENT => InvoiceItem::CATEGORY_EQUIPMENT,
        EstimateItem::CATEGORY_LABOR => InvoiceItem::CATEGORY_LABOR,
    ];

    public function sync(Invoice $invoice): void
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT || $invoice->estimate_id === null) {
            return;
        }

        $estimate = $invoice->estimate ?? $invoice->estimate()->first();

        if ($estimate === null) {
            return;
        }

        $estimate->loadMissing('items');

        // Whatever was on the invoice before this call is replaced outright —
        // called once, right when the invoice is raised from the estimate.
        $invoice->items()->delete();

        foreach ($estimate->items as $position => $item) {
            $invoice->items()->create([
                'description' => $item->description,
                'source_category' => self::CATEGORY_MAP[$item->category] ?? null,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_cost,
                'total' => $item->total,
                'position' => $position,
            ]);
        }

        $invoice->recalculateTotals();
    }
}
