<?php

namespace App\Services\JobCosting;

use App\Models\ChangeOrder;
use App\Models\Job;
use App\Services\Takeoff\EstimateBuilder;

/**
 * A job's priced scope at the three moments Analytics cares about.
 *
 * - **Baseline** — the estimate as it stood when the job was raised. This is
 *   what a job's work was "estimated" at, and it never moves afterwards.
 * - **Current** — the job's latest estimate plus its approved change orders.
 *   Every later change lands here: edits on the web, in the Estimate Builder,
 *   and the extra work the field raises as change orders.
 * - **Final** — the current scope frozen at the moment the job is completed.
 *   From then on it is what the job "actually" came to.
 *
 * Baseline and final are stored on the job (`estimate_baseline`,
 * `estimate_final`); current is always worked out live. A job raised before
 * this existed has no baseline, and is simply measured against its current
 * scope, exactly as it was before.
 */
class EstimateScope
{
    /**
     * The one place the arithmetic lives: an estimate's totals plus what its
     * approved change orders add. `JobCostSummary` calls this too, for its
     * batched path, so a snapshot and the live figure can never disagree.
     *
     * @param  array<string, float>|null  $estimateTotals  `EstimateBuilder::totalsFor()`
     * @param  array{material: float, labor: float, hours: float, sell: float}  $changeOrders
     * @return array{estimate_id: ?int, material: float, labor: float, equipment: float, labor_hours: float, total: float}
     */
    public static function combine(?int $estimateId, ?array $estimateTotals, array $changeOrders): array
    {
        return [
            'estimate_id' => $estimateId,
            'material' => round(($estimateTotals['material'] ?? 0.0) + $changeOrders['material'], 2),
            'labor' => round(($estimateTotals['labor'] ?? 0.0) + $changeOrders['labor'], 2),
            'equipment' => round($estimateTotals['equipment'] ?? 0.0, 2),
            'labor_hours' => round(($estimateTotals['laborHours'] ?? 0.0) + $changeOrders['hours'], 2),
            'total' => round(($estimateTotals['grandTotal'] ?? 0.0) + $changeOrders['sell'], 2),
        ];
    }

    /** @return array{material: float, labor: float, hours: float, sell: float} */
    public static function noChangeOrders(): array
    {
        return ['material' => 0.0, 'labor' => 0.0, 'hours' => 0.0, 'sell' => 0.0];
    }

    /** @return array{material: float, labor: float, hours: float, sell: float} */
    public function approvedChangeOrders(Job $job): array
    {
        $row = ChangeOrder::query()
            ->where('job_id', $job->id)
            ->where('status', ChangeOrder::STATUS_APPROVED)
            ->selectRaw('SUM(material_cost) as material, SUM(labor_cost) as labor, SUM(labor_hours) as hours, SUM(sell_total) as sell')
            ->first();

        return [
            'material' => (float) ($row?->material ?? 0),
            'labor' => (float) ($row?->labor ?? 0),
            'hours' => (float) ($row?->hours ?? 0),
            'sell' => (float) ($row?->sell ?? 0),
        ];
    }

    /** The job's scope right now. @return array<string, float|int|null> */
    public function current(Job $job): array
    {
        $estimate = $job->estimates()->first();

        return self::combine(
            $estimate?->id,
            $estimate ? EstimateBuilder::totalsFor($estimate) : null,
            $this->approvedChangeOrders($job),
        );
    }

    /**
     * Records the baseline once — the first time the job has a priced
     * estimate. An unpriced estimate is not a baseline yet: a job raised with
     * an empty estimate is measured against it once it has been priced.
     */
    public function captureBaseline(Job $job): void
    {
        if ($job->estimate_baseline !== null) {
            return;
        }

        $scope = $this->current($job);
        if ($scope['total'] <= 0) {
            return;
        }

        $this->write($job, 'estimate_baseline', [...$scope, 'captured_at' => now()->toIso8601String()]);
    }

    /** Freezes the scope as the job's final one, on completion. */
    public function captureFinal(Job $job): void
    {
        $this->write($job, 'estimate_final', [...$this->current($job), 'captured_at' => now()->toIso8601String()]);
    }

    /** A job taken back out of completed is live again, so its scope is no longer final. */
    public function clearFinal(Job $job): void
    {
        if ($job->estimate_final !== null) {
            $this->write($job, 'estimate_final', null);
        }
    }

    /** @param  array<string, mixed>|null  $snapshot */
    private function write(Job $job, string $column, ?array $snapshot): void
    {
        $job->forceFill([$column => $snapshot])->saveQuietly();
    }
}
