<?php

namespace App\Services\Billing;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobFieldMaterial;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts an approved field material or labor entry onto its job's estimate, and from there onto
 * the job's draft invoices, so what the crew added on site is billed. A sent invoice is never
 * altered. An entry goes on once; approving it again does nothing.
 */
class FieldMaterialEstimateSync
{
    public function approve(JobFieldMaterial $entry, User $by): void
    {
        if ($entry->isApproved() || ! $entry->isAdded()) {
            return;
        }

        $estimate = $this->estimateFor($entry);

        DB::transaction(function () use ($entry, $by, $estimate) {
            $item = $estimate->items()->create([
                'job_task_id' => $entry->job_task_id,
                'category' => $entry->isLabor() ? EstimateItem::CATEGORY_LABOR : EstimateItem::CATEGORY_MATERIAL,
                'description' => $entry->description,
                'unit' => $entry->isLabor() ? 'hr' : ($entry->unit ?: 'ea'),
                'quantity' => $entry->actual_quantity,
                'unit_cost' => $entry->unit_price,
                'total' => $entry->total,
                'source' => 'field',
                'position' => (int) $estimate->items()->max('position') + 1,
            ]);

            $estimate->recalculateTotals();

            $entry->forceFill([
                'status' => JobFieldMaterial::STATUS_APPROVED,
                'added_estimate_item_id' => $item->id,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            $estimate->job->invoices()->where('status', Invoice::STATUS_DRAFT)->where('estimate_id', $estimate->id)->get()
                ->each(function (Invoice $invoice) use ($item) {
                    $invoice->items()->create([
                        'description' => $item->description,
                        'source_category' => $item->category === EstimateItem::CATEGORY_LABOR
                            ? InvoiceItem::CATEGORY_LABOR
                            : InvoiceItem::CATEGORY_MATERIAL,
                        'source' => InvoiceItem::SOURCE_ESTIMATE,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_cost,
                        'total' => $item->total,
                        'position' => (int) $invoice->items()->max('position') + 1,
                    ]);
                    $invoice->recalculateTotals();
                });
        });
    }

    /** The estimate the job was built from: its newest standalone one, else any it has. */
    private function estimateFor(JobFieldMaterial $entry): Estimate
    {
        $job = $entry->job;

        $estimate = $job->estimates()->reorder()
            ->orderByRaw("case when kind = 'standalone' then 0 else 1 end")
            ->orderByDesc('id')
            ->first();

        if ($estimate === null) {
            throw ValidationException::withMessages(['estimate' => 'This job has no estimate to add this to.']);
        }

        return $estimate->setRelation('job', $job);
    }
}
