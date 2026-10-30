<?php

namespace App\Services\JobScope;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobCostEntry;
use Illuminate\Support\Facades\Auth;

/**
 * Carries a change a user makes on a draft invoice back into the job, so the
 * job's actual agrees with the bill — whoever touched it last wins.
 *
 * A line on the invoice says how much of it the job's scope already accounts
 * for (`scope_amount`). Whatever it is above or below that is an adjustment,
 * kept as one cost entry per line (`source = invoice`). The next time the
 * scope moves, the invoice is rebuilt from the job — adjustments included —
 * so the amounts come out the same and nothing a user changed is lost.
 *
 * Only material, equipment and other lines are carried back. Labor is the
 * hours people logged (edit them where they are logged), and a change-order
 * line is billed from its change order.
 */
class InvoiceScopeAdjuster
{
    private const CATEGORIES = [InvoiceItem::CATEGORY_MATERIAL, InvoiceItem::CATEGORY_EQUIPMENT, InvoiceItem::CATEGORY_OTHER];

    /** A line was added or changed. `$totalBefore` is what it was before this edit (null for a new line). */
    public function saved(Invoice $invoice, InvoiceItem $item, ?float $totalBefore): void
    {
        if (! $this->applies($invoice, $item)) {
            // A line moved out of the categories the job tracks no longer carries an adjustment.
            JobCostEntry::query()->where('job_id', $invoice->job_id)->where('invoice_item_id', $item->id)->get()->each->delete();

            return;
        }

        $scopeAmount = $this->scopeAmount($item, $totalBefore);

        // Remembered on the line, so every later edit is measured against the same starting point.
        if ($item->scope_amount === null) {
            $item->forceFill(['scope_amount' => $scopeAmount])->saveQuietly();
        }

        $this->adjust($invoice, $item, $scopeAmount);
    }

    /** A line was taken off the invoice, so the scope it accounted for is no longer billed. */
    public function removed(Invoice $invoice, InvoiceItem $item): void
    {
        if (! $this->applies($invoice, $item)) {
            return;
        }

        $this->adjust($invoice, $item, $this->scopeAmount($item, (float) $item->total), removed: true);
    }

    private function applies(Invoice $invoice, InvoiceItem $item): bool
    {
        return $invoice->status === Invoice::STATUS_DRAFT
            && $invoice->job_id !== null
            && $item->change_order_id === null
            && in_array($item->source_category, self::CATEGORIES, true);
    }

    /** What the job's scope already holds for this line: a typed line holds nothing, a synced one holds what it was. */
    private function scopeAmount(InvoiceItem $item, ?float $totalBefore): float
    {
        if ($item->scope_amount !== null) {
            return (float) $item->scope_amount;
        }

        return $item->source === InvoiceItem::SOURCE_MANUAL ? 0.0 : (float) ($totalBefore ?? 0);
    }

    private function adjust(Invoice $invoice, InvoiceItem $item, float $scopeAmount, bool $removed = false): void
    {
        $target = round(($removed ? 0.0 : (float) $item->total) - $scopeAmount, 2);
        $existing = JobCostEntry::query()->where('job_id', $invoice->job_id)->where('invoice_item_id', $item->id)->first();

        if ($target == 0.0) {
            // Back to what the scope says: whatever adjustment this line carried is withdrawn.
            $existing?->delete();

            return;
        }

        $attributes = [
            'category' => $item->source_category,
            'description' => "Invoice {$invoice->invoice_number}: {$item->description}",
            'amount' => $target,
            'incurred_on' => now()->toDateString(),
            'recorded_by' => Auth::id(),
            'source' => ChangeSource::INVOICE,
            'invoice_id' => $invoice->id,
        ];

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            JobCostEntry::create($attributes + ['job_id' => $invoice->job_id, 'invoice_item_id' => $item->id]);
        }
    }
}
