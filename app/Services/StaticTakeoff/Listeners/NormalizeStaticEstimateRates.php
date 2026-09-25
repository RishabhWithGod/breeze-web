<?php

namespace App\Services\StaticTakeoff\Listeners;

use App\Events\EstimateGenerated;
use App\Services\Takeoff\EstimateBuilder;

/**
 * Sets an estimate's tax and markup percentages from a static dataset's own
 * `estimate.tax_rate`/`estimate.markup_rate`, when it was raised from one.
 *
 * `EstimateBuilder::open()` reads `tax_pct` off `engineEstimate['tax_rate']`
 * itself — but only when that rate is *greater than* zero
 * (`taxPercent()`'s `if ($rate > 0)` treats 0 the same as "not set" and
 * falls through to the project's rate book/price book/config). A dataset
 * with a genuinely 0% tax rate would silently pick up a real company tax
 * otherwise, so both `tax_pct` and `markup_pct` — which has no dataset hook
 * at all — are set explicitly here, right after `EstimateBuilder` creates
 * the estimate, without `EstimateBuilder` itself needing to know static
 * mode exists.
 */
class NormalizeStaticEstimateRates
{
    public function handle(EstimateGenerated $event): void
    {
        $estimate = $event->estimate;
        $result = $estimate->aiResult;

        if (($result?->original_payload['_synced'] ?? false) !== true) {
            return;
        }

        $estimateData = $result->original_payload['estimate'] ?? [];
        $taxRate = (float) ($estimateData['tax_rate'] ?? 0);
        $taxPct = round(($taxRate <= 1 ? $taxRate * 100 : $taxRate), 2);
        $markupPct = round((float) ($estimateData['markup_rate'] ?? 0) * 100, 2);

        if ((float) $estimate->tax_pct === $taxPct && (float) $estimate->markup_pct === $markupPct) {
            return;
        }

        $estimate->update(['tax_pct' => $taxPct, 'markup_pct' => $markupPct]);
        $estimate->recalculateTotals();

        app(EstimateBuilder::class)->syncJobBudget($estimate->fresh());
    }
}
