<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Services\ChangeOrders\ChangeOrderBilling;
use App\Services\JobCosting\EstimateScope;
use App\Services\JobCosting\JobCostSummary;
use App\Services\Takeoff\EstimateBuilder;

/**
 * Keeps a job's draft invoice(s) billing exactly what Job Costing's Actual
 * card says — the moment Labor (Journeyman hours) or Material/Equipment/
 * Other (a cost entry) changes, so Subtotal/Tax/Total on the invoice can
 * never drift from the Actual figures behind them.
 *
 * A draft invoice's lines become these four categories, replacing whatever
 * was on it before (typically a copy of the estimate's own items) — once a
 * job's real costs are being tracked, that is the invoice's source of truth,
 * not a frozen snapshot from whenever it was first raised. A sent or paid
 * invoice is never touched: it has already gone to the client, and altering
 * a bill after it was sent is not this feature's job.
 */
class ActualCostInvoiceSync
{
    public function __construct(
        private readonly JobCostSummary $costSummary,
        private readonly EstimateBuilder $estimateBuilder,
    ) {}

    public function sync(Job $job, ?int $exceptInvoiceId = null): void
    {
        $invoices = $job->invoices()->where('status', Invoice::STATUS_DRAFT)
            ->when($exceptInvoiceId, fn ($q) => $q->whereKeyNot($exceptInvoiceId))
            ->get();

        if ($invoices->isEmpty()) {
            return;
        }

        $summary = $this->costSummary->for($job);
        // An approved change order is billed on its own line below, so what it adds to materials is not billed twice.
        $summary['actualMaterialCost'] = round(max(0.0, $summary['actualMaterialCost'] - app(EstimateScope::class)->approvedChangeOrders($job)['material']), 2);
        $laborRate = $job->project !== null ? $this->estimateBuilder->laborRateFor($job->project) : 0.0;

        foreach ($invoices as $invoice) {
            $this->syncInvoice($invoice, $summary, $laborRate);
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function syncInvoice(Invoice $invoice, array $summary, float $laborRate): void
    {
        // The four category lines are updated where they stand, not deleted and written again: a person with
        // one open (to edit it) must still find it there when a teammate's change brings the bill up to date.
        // Anything else on the invoice — typed lines (their amounts are already carried in the job's costs),
        // change-order lines (re-added below) — is cleared out.
        $existing = $invoice->items()
            ->whereNull('change_order_id')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', InvoiceItem::SOURCE_MANUAL))
            ->orderBy('position')
            ->get()
            ->groupBy('source_category')
            ->map(fn ($group) => $group->first());

        $lines = [
            [
                'category' => InvoiceItem::CATEGORY_LABOR,
                'description' => 'Labor',
                'quantity' => $summary['actualLaborHours'],
                'unitPrice' => $laborRate,
                'total' => $summary['actualLaborCost'],
            ],
            [
                'category' => InvoiceItem::CATEGORY_MATERIAL,
                'description' => 'Materials',
                'quantity' => 1,
                'unitPrice' => $summary['actualMaterialCost'],
                'total' => $summary['actualMaterialCost'],
            ],
            [
                'category' => InvoiceItem::CATEGORY_EQUIPMENT,
                'description' => 'Equipment',
                'quantity' => 1,
                'unitPrice' => $summary['actualEquipmentCost'],
                'total' => $summary['actualEquipmentCost'],
            ],
            [
                'category' => InvoiceItem::CATEGORY_OTHER,
                'description' => 'Other',
                'quantity' => 1,
                'unitPrice' => $summary['actualOtherCost'],
                'total' => $summary['actualOtherCost'],
            ],
        ];

        $position = 0;
        $kept = [];

        foreach ($lines as $line) {
            // A category with nothing actually incurred is left off the bill
            // rather than shown as a zero line.
            if ($line['total'] <= 0) {
                continue;
            }

            $attributes = [
                'description' => $line['description'],
                'source_category' => $line['category'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unitPrice'],
                'total' => round($line['total'], 2),
                // The line is exactly what the job's scope says, so nothing on it is an invoice-side change yet.
                'scope_amount' => round($line['total'], 2),
                'position' => $position++,
            ];

            $item = $existing->get($line['category']);
            if ($item !== null) {
                $item->update($attributes);
                $kept[] = $item->id;
            } else {
                $kept[] = $invoice->items()->create($attributes)->id;
            }
        }

        $invoice->items()->whereNotIn('id', $kept)->delete();

        // Approved change orders are billed on top of the job's actual costs.
        app(ChangeOrderBilling::class)->attachApprovedTo($invoice);

        $invoice->recalculateTotals();
    }
}
