<?php

namespace App\Listeners;

use App\Events\JobStatusChanged;
use App\Models\Invoice;
use App\Services\JobCosting\JobCostSummary;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Bills the job the moment it's finished — the real costs the crew logged
 * (labor from approved time entries, materials/equipment/other from job cost
 * entries) become a draft invoice, in the same Invoices screen and PDF every
 * other invoice already uses. A manager reviews, sends and gets paid against
 * it from there; nothing new to learn.
 *
 * Only ever generated once per job: a job that already has an invoice
 * (billed earlier, or re-completed after being reopened) is left alone
 * rather than doubled up, and a job with no client on file has nothing to
 * invoice against and is skipped. A job that finished with nothing actually
 * logged against it (no approved time, no cost entries) has nothing to bill
 * either, so no empty invoice is raised.
 */
class GenerateInvoiceOnJobCompletion implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly JobCostSummary $costs) {}

    public function handle(JobStatusChanged $event): void
    {
        if ($event->to !== 'completed') {
            return;
        }

        $job = $event->job;

        if ($job->client_id === null || $job->invoices()->exists()) {
            return;
        }

        $summary = $this->costs->for($job);

        $lines = array_values(array_filter([
            $summary['actualLaborCost'] > 0 ? [
                'description' => "Labor — {$summary['actualLaborHours']} hrs",
                'quantity' => $summary['actualLaborHours'] > 0 ? $summary['actualLaborHours'] : 1,
                'unit_price' => $summary['actualLaborHours'] > 0
                    ? round($summary['actualLaborCost'] / $summary['actualLaborHours'], 2)
                    : $summary['actualLaborCost'],
                'total' => $summary['actualLaborCost'],
            ] : null,
            $summary['actualMaterialCost'] > 0 ? [
                'description' => 'Materials',
                'quantity' => 1,
                'unit_price' => $summary['actualMaterialCost'],
                'total' => $summary['actualMaterialCost'],
            ] : null,
            $summary['actualEquipmentCost'] > 0 ? [
                'description' => 'Equipment',
                'quantity' => 1,
                'unit_price' => $summary['actualEquipmentCost'],
                'total' => $summary['actualEquipmentCost'],
            ] : null,
            $summary['actualOtherCost'] > 0 ? [
                'description' => 'Other costs',
                'quantity' => 1,
                'unit_price' => $summary['actualOtherCost'],
                'total' => $summary['actualOtherCost'],
            ] : null,
        ]));

        if ($lines === []) {
            return;
        }

        $invoice = Invoice::create([
            'user_id' => $job->user_id,
            'invoice_number' => Invoice::nextNumber($job->owner),
            'job_id' => $job->id,
            'estimate_id' => $summary['estimateId'],
            'client_id' => $job->client_id,
            'client' => $job->client,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_pct' => 0,
            'status' => Invoice::STATUS_DRAFT,
            'notes' => "Generated automatically when \"{$job->name}\" was marked completed.",
            'created_by' => $job->user_id,
        ]);

        foreach ($lines as $position => $line) {
            $invoice->items()->create([...$line, 'position' => $position]);
        }

        $invoice->recalculateTotals();
    }
}
