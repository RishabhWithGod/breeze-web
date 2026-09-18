<?php

namespace App\Services\JobCosting;

use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Support\Collection;

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
 * Labor's actual hours no longer wait on the Time Tracking approval workflow —
 * every one of a person's time entries on the job counts the moment it is
 * logged (see `journeymanHours()`), and a manager can also set the real total
 * directly on the Billing screen; that saved override, once it exists, wins
 * outright over whatever the raw entries add up to. `locked` entries are the
 * one status left out everywhere: a `locked` row has been superseded by the
 * correction that replaced it, so counting both would double the same
 * physical hours. Actual labor cost is that same total priced at the
 * project's own effective labor rate (`EstimateBuilder::laborRateFor()`) —
 * not `time_entries.labor_cost`, which is only ever filled in when a rate
 * happened to be on hand at the moment the entry was logged.
 *
 * There is no purchasing/inventory system anywhere in this app, so a
 * material/equipment line's real, priced total already lives in exactly one
 * place: the estimate the job was actually priced from — the same one
 * `estimatedMaterialCost`/`estimatedEquipmentCost` below read. Actual starts
 * there too, plus whatever a manager has separately logged in
 * `job_cost_entries` on top of it — a real overage, never invented. "Other"
 * has no comparable estimate bucket, so it stays `job_cost_entries`-only.
 */
class JobCostSummary
{
    public function __construct(private readonly EstimateBuilder $estimateBuilder) {}

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

        $journeymanHours = $this->journeymanHours($job, $from, $to);
        $actualLaborHours = round($journeymanHours->sum('hours'), 2);

        $project = $job->project;
        $laborRate = $project !== null ? $this->estimateBuilder->laborRateFor($project) : 0.0;
        $actualLaborCost = round($actualLaborHours * $laborRate, 2);

        $costEntries = $job->costEntries()->incurredBetween($from, $to);
        $actualMaterialCost = round($estimatedMaterialCost + (float) $costEntries->clone()->where('category', JobCostEntry::CATEGORY_MATERIAL)->sum('amount'), 2);
        $actualEquipmentCost = round($estimatedEquipmentCost + (float) $costEntries->clone()->where('category', JobCostEntry::CATEGORY_EQUIPMENT)->sum('amount'), 2);
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
     * Every person with logged time on this job, their effective total
     * hours, and whether that total is a manager's own saved figure or just
     * the raw sum of their time entries — the Billing screen's "Journeyman
     * Hours" card, and also what `actualLaborHours` above is built from, so
     * the card and the Actual total can never disagree.
     *
     * No approval wait: every entry counts the moment it exists (`locked`
     * excluded — its correction is what should count instead, not both). A
     * saved `job_journeyman_hours` row, once one exists for that person,
     * replaces their raw total outright.
     *
     * @return Collection<int, array{userId: int, name: string, role: ?string, rawHours: float, hours: float, isOverridden: bool}>
     */
    public function journeymanHours(Job $job, ?string $from = null, ?string $to = null): Collection
    {
        $rawByUser = $job->timeEntries()
            ->where('status', '!=', TimeEntry::STATUS_LOCKED)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->with(['teamMember', 'user'])
            ->get()
            ->groupBy('user_id');

        $overrides = $job->journeymanHours()->get()->keyBy('user_id');

        $userIds = $rawByUser->keys()->merge($overrides->keys())->unique()->filter();
        $users = User::whereKey($userIds)->get(['id', 'name', 'role'])->keyBy('id');

        return $userIds->map(function (int $userId) use ($rawByUser, $overrides, $users) {
            $entries = $rawByUser->get($userId);
            $rawHours = round((float) $entries?->sum('hours'), 2);
            $override = $overrides->get($userId);
            $user = $users->get($userId);
            $teamMember = $entries?->first()?->teamMember;

            return [
                'userId' => $userId,
                'name' => $teamMember?->name ?? $user?->name ?? 'Unknown',
                'role' => $teamMember?->role ?? $user?->role,
                'rawHours' => $rawHours,
                'hours' => $override !== null ? round((float) $override->hours, 2) : $rawHours,
                'isOverridden' => $override !== null,
            ];
        })->sortByDesc('hours')->values();
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
