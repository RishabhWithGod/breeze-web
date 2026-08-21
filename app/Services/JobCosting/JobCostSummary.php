<?php

namespace App\Services\JobCosting;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TimeEntry;
use App\Services\Takeoff\EstimateBuilder;

/**
 * The Job Costing read model: estimated vs actual across labor, materials,
 * equipment and other cost, plus revenue/billing and the profit they net out
 * to — for one job, over an optional date window.
 *
 * "Estimated" figures are the job's fixed plan (from its tasks and its
 * estimate) and are never date-scoped — an estimate does not decompose by
 * date. "Actual" figures are scoped to `[$from, $to]` when given, so the
 * dashboard's date range genuinely narrows what counts as having happened,
 * not just which jobs are listed.
 *
 * Labor's actual cost has a real source already — approved `time_entries`
 * rows, the same ones `JobLaborSummary` reads — so it is queried directly
 * here rather than duplicated through that service, whose own contract is
 * deliberately all-time (`Job Detail`'s time-tracking widget wants "this
 * job's whole history", not whatever window the costing dashboard has
 * selected). Materials/equipment/other have no comparable source anywhere in
 * the schema, so they come from `job_cost_entries` — a person's real record
 * of a real cost, never a guess.
 */
class JobCostSummary
{
    /**
     * @return array{
     *     jobId: int, jobName: string, jobStatus: string, client: ?string,
     *     estimateId: ?int, estimateNumber: ?string,
     *     estimatedLaborHours: float, actualLaborHours: float, laborHoursVariance: float,
     *     estimatedLaborCost: float, actualLaborCost: float, laborCostVariance: float,
     *     estimatedMaterialCost: float, actualMaterialCost: float, materialCostVariance: float,
     *     estimatedEquipmentCost: float, actualEquipmentCost: float, equipmentCostVariance: float,
     *     estimatedOtherCost: float, actualOtherCost: float, otherCostVariance: float,
     *     estimatedTotalCost: float, actualTotalCost: float, totalCostVariance: float, totalCostVariancePct: ?float,
     *     revenue: float, billed: float, paid: float, outstanding: float, unbilled: float,
     *     profit: float, marginPct: ?float,
     *     isOverBudget: bool, overrunReason: ?string, overrunAmount: float, overrunPct: ?float,
     * }
     */
    public function for(Job $job, ?string $from = null, ?string $to = null): array
    {
        $estimate = $job->estimates()->latest('issued_on')->first();
        $estimateTotals = $estimate ? EstimateBuilder::totalsFor($estimate) : null;

        $estimatedLaborHours = round((float) $job->tasks()->sum('estimated_hours'), 2);
        $estimatedLaborCost = round($estimateTotals['labor'] ?? 0.0, 2);
        $estimatedMaterialCost = round($estimateTotals['material'] ?? 0.0, 2);
        $estimatedEquipmentCost = round($estimateTotals['equipment'] ?? 0.0, 2);
        // The estimate has no distinct "other" bucket — never invented, left at 0.
        $estimatedOtherCost = 0.0;
        $estimatedTotalCost = round($estimatedLaborCost + $estimatedMaterialCost + $estimatedEquipmentCost + $estimatedOtherCost, 2);

        $timeQuery = $job->timeEntries()
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to));
        $actualLaborHours = round((float) $timeQuery->clone()->sum('hours'), 2);
        $actualLaborCost = round((float) $timeQuery->clone()->sum('labor_cost'), 2);

        $costEntries = $job->costEntries()->incurredBetween($from, $to);
        $actualMaterialCost = round((float) $costEntries->clone()->where('category', JobCostEntry::CATEGORY_MATERIAL)->sum('amount'), 2);
        $actualEquipmentCost = round((float) $costEntries->clone()->where('category', JobCostEntry::CATEGORY_EQUIPMENT)->sum('amount'), 2);
        $actualOtherCost = round((float) $costEntries->clone()->where('category', JobCostEntry::CATEGORY_OTHER)->sum('amount'), 2);
        $actualTotalCost = round($actualLaborCost + $actualMaterialCost + $actualEquipmentCost + $actualOtherCost, 2);

        $invoices = $job->invoices();
        $billed = round((float) $invoices->clone()->where('status', '!=', Invoice::STATUS_DRAFT)->sum('total'), 2);
        $paid = round((float) $invoices->clone()->sum('paid_amount'), 2);
        // The contract value when one has actually been priced; an estimate
        // that exists but was never given line items has no real value yet,
        // so it falls through to whatever has genuinely been invoiced —
        // `?? $billed` alone would not do this, since `grandTotal` is `0.0`
        // (not null) on an unpriced estimate and null-coalescing never fires.
        $hasPricedEstimate = $estimateTotals !== null && $estimateTotals['grandTotal'] > 0;
        $revenue = round($hasPricedEstimate ? $estimateTotals['grandTotal'] : $billed, 2);
        $outstanding = round(max(0, $billed - $paid), 2);
        $unbilled = round(max(0, $revenue - $billed), 2);

        $profit = round($revenue - $actualTotalCost, 2);
        $marginPct = $revenue > 0 ? round($profit / $revenue * 100, 1) : null;

        $totalCostVariance = round($actualTotalCost - $estimatedTotalCost, 2);
        $totalCostVariancePct = $estimatedTotalCost > 0 ? round($totalCostVariance / $estimatedTotalCost * 100, 1) : null;

        [$overrunReason, $overrunAmount, $overrunPct] = $this->overrun(
            $estimatedLaborCost, $actualLaborCost, $estimatedLaborHours, $actualLaborHours,
            $estimatedMaterialCost, $actualMaterialCost, $estimatedTotalCost, $actualTotalCost,
        );

        return [
            'jobId' => $job->id,
            'jobName' => $job->name,
            'jobStatus' => $job->status,
            'client' => $job->client,
            'estimateId' => $estimate?->id,
            'estimateNumber' => $estimate?->number,

            'estimatedLaborHours' => $estimatedLaborHours,
            'actualLaborHours' => $actualLaborHours,
            'laborHoursVariance' => round($actualLaborHours - $estimatedLaborHours, 2),
            'estimatedLaborCost' => $estimatedLaborCost,
            'actualLaborCost' => $actualLaborCost,
            'laborCostVariance' => round($actualLaborCost - $estimatedLaborCost, 2),

            'estimatedMaterialCost' => $estimatedMaterialCost,
            'actualMaterialCost' => $actualMaterialCost,
            'materialCostVariance' => round($actualMaterialCost - $estimatedMaterialCost, 2),

            'estimatedEquipmentCost' => $estimatedEquipmentCost,
            'actualEquipmentCost' => $actualEquipmentCost,
            'equipmentCostVariance' => round($actualEquipmentCost - $estimatedEquipmentCost, 2),

            'estimatedOtherCost' => $estimatedOtherCost,
            'actualOtherCost' => $actualOtherCost,
            'otherCostVariance' => round($actualOtherCost - $estimatedOtherCost, 2),

            'estimatedTotalCost' => $estimatedTotalCost,
            'actualTotalCost' => $actualTotalCost,
            'totalCostVariance' => $totalCostVariance,
            'totalCostVariancePct' => $totalCostVariancePct,

            'revenue' => $revenue,
            'billed' => $billed,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'unbilled' => $unbilled,

            'profit' => $profit,
            'marginPct' => $marginPct,

            'isOverBudget' => $overrunReason !== null,
            'overrunReason' => $overrunReason,
            'overrunAmount' => $overrunAmount,
            'overrunPct' => $overrunPct,
        ];
    }

    /**
     * Nulls every dollar-denominated field on a `for()` row — the one place
     * that shape's redaction rule lives, so every controller sending this
     * row to a role without `viewJobCosts` reuses it rather than
     * re-deciding which keys are sensitive. Hours, status, and the overrun
     * reason/percentage stay: this codebase treats those as not sensitive on
     * their own, only the dollar amounts are.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function redact(array $row): array
    {
        foreach ([
            'estimatedLaborCost', 'actualLaborCost', 'laborCostVariance',
            'estimatedMaterialCost', 'actualMaterialCost', 'materialCostVariance',
            'estimatedEquipmentCost', 'actualEquipmentCost', 'equipmentCostVariance',
            'estimatedOtherCost', 'actualOtherCost', 'otherCostVariance',
            'estimatedTotalCost', 'actualTotalCost', 'totalCostVariance', 'totalCostVariancePct',
            'revenue', 'billed', 'paid', 'outstanding', 'unbilled',
            'profit', 'marginPct', 'overrunAmount',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * The single worst-overrun reason for this job, if any — labor hours,
     * labor cost, material cost, or the total. Checked in that order since a
     * labor-hours overrun is usually the earliest signal a PM can act on.
     *
     * @return array{0: ?string, 1: float, 2: ?float}
     */
    private function overrun(
        float $estLaborCost, float $actLaborCost, float $estLaborHours, float $actLaborHours,
        float $estMaterialCost, float $actMaterialCost, float $estTotal, float $actTotal,
    ): array {
        if ($estLaborHours > 0 && $actLaborHours > $estLaborHours) {
            $amount = round($actLaborCost - $estLaborCost, 2);
            $pct = round(($actLaborHours - $estLaborHours) / $estLaborHours * 100, 1);

            return ['labor_hours', max(0, $amount), $pct];
        }

        if ($estLaborCost > 0 && $actLaborCost > $estLaborCost) {
            $amount = round($actLaborCost - $estLaborCost, 2);

            return ['labor_cost', $amount, round($amount / $estLaborCost * 100, 1)];
        }

        if ($estMaterialCost > 0 && $actMaterialCost > $estMaterialCost) {
            $amount = round($actMaterialCost - $estMaterialCost, 2);

            return ['material_cost', $amount, round($amount / $estMaterialCost * 100, 1)];
        }

        if ($estTotal > 0 && $actTotal > $estTotal) {
            $amount = round($actTotal - $estTotal, 2);

            return ['total_cost', $amount, round($amount / $estTotal * 100, 1)];
        }

        return [null, 0.0, null];
    }
}
