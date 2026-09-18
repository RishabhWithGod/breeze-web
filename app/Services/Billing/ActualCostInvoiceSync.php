<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
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

    public function sync(Job $job): void
    {
        $invoices = $job->invoices()->where('status', Invoice::STATUS_DRAFT)->get();

        if ($invoices->isEmpty()) {
            return;
        }

        $summary = $this->costSummary->for($job);
        $laborRate = $job->project !== null ? $this->estimateBuilder->laborRateFor($job->project) : 0.0;

        foreach ($invoices as $invoice) {
            $this->syncInvoice($invoice, $summary, $laborRate);
        }
    }

    /** @param  array<string, mixed>  $summary */
    private function syncInvoice(Invoice $invoice, array $summary, float $laborRate): void
    {
        // Rebuilt from scratch each time — the four lines below are the
        // whole of a job-linked invoice's content from here on.
        $invoice->items()->delete();

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

        foreach ($lines as $line) {
            // A category with nothing actually incurred is left off the bill
            // rather than shown as a zero line.
            if ($line['total'] <= 0) {
                continue;
            }

            $invoice->items()->create([
                'description' => $line['description'],
                'source_category' => $line['category'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unitPrice'],
                'total' => round($line['total'], 2),
                'position' => $position++,
            ]);
        }

        $invoice->recalculateTotals();
    }
}
