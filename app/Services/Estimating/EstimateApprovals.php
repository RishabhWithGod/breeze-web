<?php

namespace App\Services\Estimating;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * An estimate's way through approval: sent, then approved or sent back.
 *
 * Every time it is sent a revision is written down — version, what changed,
 * who, and what it came to — so the one that was approved can always be named.
 * Approving locks that revision and records who and when; sending it back
 * reopens the worksheet and keeps what the reviewer wrote.
 */
class EstimateApprovals
{
    /** Sends the estimate for approval and writes the revision it is at. */
    public function submit(Estimate $estimate, User $user, ?string $note): EstimateRevision
    {
        return DB::transaction(function () use ($estimate, $user, $note) {
            $estimate->refresh();
            $previous = $estimate->revisions()->first();

            $revision = $estimate->revisions()->create([
                'version' => ($previous?->version ?? 0) + 1,
                'changes' => filled($note) ? trim($note) : $this->describeChange($estimate, $previous),
                'user_id' => $user->id,
                'total' => $estimate->grand_total,
                'item_count' => $estimate->builderLines()->count() ?: $estimate->items()->count(),
            ]);

            // "Sent" is where an estimate waits to be approved or turned down; whatever a
            // reviewer said last time has been answered by this new version.
            $estimate->update(['status' => 'sent', 'review_notes' => null]);

            return $revision;
        });
    }

    /** Approves it: the current revision is the approved one, and who and when are kept. */
    public function approve(Estimate $estimate, User $reviewer, ?string $notes): void
    {
        DB::transaction(function () use ($estimate, $reviewer, $notes) {
            $estimate->update(['status' => 'approved', 'review_notes' => filled($notes) ? trim($notes) : null]);

            $estimate->approved_by = $reviewer->id;
            $estimate->approved_at = now();
            $estimate->approved_revision = $estimate->revisions()->value('version');
            $estimate->reviewed_by = $reviewer->id;
            $estimate->reviewed_at = now();
            $estimate->save();
        });
    }

    /** Sends it back to draft with the reviewer's notes, so it can be edited again. */
    public function returnForEdits(Estimate $estimate, User $reviewer, ?string $notes): void
    {
        DB::transaction(function () use ($estimate, $reviewer, $notes) {
            $estimate->update(['status' => 'draft', 'review_notes' => filled($notes) ? trim($notes) : null]);

            $estimate->approved_by = null;
            $estimate->approved_at = null;
            $estimate->approved_revision = null;
            $estimate->reviewed_by = $reviewer->id;
            $estimate->reviewed_at = now();
            $estimate->save();
        });
    }

    /**
     * Where the money goes, by category, with each one's share of the total.
     *
     * A builder estimate is read by commodity, with each row's own markup in it; any
     * other by the kind of line (materials, labor, equipment…), with the markup on
     * top. Tax is a line of its own, so the rows add up to the estimate's total.
     *
     * @return array{rows: list<array{category: string, amount: float, pct: float}>, cost: float, markup: float, tax: float, total: float}
     */
    public function lineSummary(Estimate $estimate): array
    {
        $rows = [];

        if ($estimate->builder_managed && $estimate->builderLines()->exists()) {
            foreach ($estimate->builderLines as $line) {
                $name = $line->commodity ?: 'Uncategorised';
                $rows[$name] = round(($rows[$name] ?? 0) + $line->subtotal(), 2);
            }
        } else {
            foreach ($estimate->items as $item) {
                $name = EstimateItem::CATEGORY_LABELS[$item->category] ?? ucfirst((string) $item->category);
                $rows[$name] = round(($rows[$name] ?? 0) + (float) $item->total, 2);
            }
            if ((float) $estimate->markup_total > 0) {
                $rows['Markup'] = (float) $estimate->markup_total;
            }
        }

        if ((float) $estimate->tax_total > 0) {
            $rows['Tax'] = (float) $estimate->tax_total;
        }

        $total = (float) $estimate->grand_total;

        return [
            'rows' => collect($rows)->map(fn (float $amount, string $category) => [
                'category' => $category,
                'amount' => $amount,
                'pct' => $total > 0 ? round($amount / $total * 100, 1) : 0.0,
            ])->values()->all(),
            'cost' => round((float) $estimate->material_total + (float) $estimate->labor_total + (float) $estimate->equipment_total, 2),
            'markup' => (float) $estimate->markup_total,
            'tax' => (float) $estimate->tax_total,
            'total' => $total,
        ];
    }

    /** What changed since the last revision, in words — used when the estimator wrote nothing. */
    private function describeChange(Estimate $estimate, ?EstimateRevision $previous): string
    {
        if ($previous === null) {
            return 'Initial estimate';
        }

        $items = $estimate->builderLines()->count() ?: $estimate->items()->count();
        $parts = [];

        if (abs((float) $estimate->grand_total - (float) $previous->total) >= 0.005) {
            $parts[] = 'Total $'.number_format((float) $previous->total, 2).' → $'.number_format((float) $estimate->grand_total, 2);
        }
        if ($items !== $previous->item_count) {
            $difference = $items - $previous->item_count;
            $parts[] = abs($difference).' '.str('item')->plural(abs($difference)).($difference > 0 ? ' added' : ' removed');
        }

        return $parts === [] ? 'Resubmitted with no change in figures' : implode('; ', $parts);
    }
}
