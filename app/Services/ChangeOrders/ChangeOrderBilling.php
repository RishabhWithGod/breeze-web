<?php

namespace App\Services\ChangeOrders;

use App\Models\ChangeOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;

/**
 * Puts an approved change order's sell amount on the job's billing: one line on the job's
 * invoice, once. An invoice that has already been sent is never altered.
 */
class ChangeOrderBilling
{
    /** Adds the change order to each of its job's draft invoices. */
    public function attach(ChangeOrder $co): void
    {
        if ($co->status !== ChangeOrder::STATUS_APPROVED) {
            return;
        }

        foreach ($co->job->invoices()->where('status', Invoice::STATUS_DRAFT)->get() as $invoice) {
            $this->line($invoice, $co);
        }
    }

    /** Adds every approved change order of the invoice's job to it — for an invoice being raised now. */
    public function attachApprovedTo(Invoice $invoice): void
    {
        if ($invoice->job_id === null || $invoice->status !== Invoice::STATUS_DRAFT) {
            return;
        }

        ChangeOrder::query()->where('job_id', $invoice->job_id)->where('status', ChangeOrder::STATUS_APPROVED)->orderBy('number')
            ->each(fn (ChangeOrder $co) => $this->line($invoice, $co));
    }

    private function line(Invoice $invoice, ChangeOrder $co): void
    {
        if ($invoice->items()->where('change_order_id', $co->id)->exists() || (float) $co->sell_total <= 0) {
            return;
        }

        $invoice->items()->create([
            'change_order_id' => $co->id,
            'description' => "{$co->label()} — {$co->description}",
            'source' => InvoiceItem::SOURCE_CHANGE_ORDER,
            'quantity' => 1,
            'unit_price' => $co->sell_total,
            'total' => $co->sell_total,
            'position' => (int) $invoice->items()->max('position') + 1,
        ]);

        $invoice->recalculateTotals();
    }
}
