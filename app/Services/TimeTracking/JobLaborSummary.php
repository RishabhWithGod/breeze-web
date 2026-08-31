<?php

namespace App\Services\TimeTracking;

use App\Models\Job;
use App\Models\TimeEntry;
use App\Services\Takeoff\EstimateBuilder;

/**
 * The job-level labor read model: estimated vs actual hours, cost, and the
 * estimate's own labor-hour figure for comparison.
 *
 * There is no Job Costing or Billing module yet — both are still placeholder
 * sidebar entries with no backing tables. This is written so a future one can
 * consume it (by job id), not so it writes into something that does not
 * exist. Everything here is a live aggregate, not a stored duplicate of the
 * time entries it summarises — `Job Detail`, the reports, and any future
 * costing screen all call this rather than each computing their own version.
 */
class JobLaborSummary
{
    /**
     * @return array{
     *     estimatedHours: float, actualHours: float, remainingHours: float,
     *     billableHours: float, overtimeHours: float, laborCost: float,
     *     billableAmount: float, pendingApprovalHours: float,
     *     estimateLaborHours: ?float, estimateVarianceHours: ?float,
     * }
     */
    public function for(Job $job): array
    {
        // Locked rows are superseded originals — their correction is what counts.
        $counted = $job->timeEntries()->where('status', TimeEntry::STATUS_APPROVED);

        $estimatedHours = (float) $job->tasks()->sum('estimated_hours');
        $actualHours = (float) $counted->clone()->sum('hours');
        $billableHours = (float) $counted->clone()->where('billable', true)->sum('hours');
        $laborCost = (float) $counted->clone()->sum('labor_cost');
        $billableAmount = (float) $counted->clone()->where('billable', true)->sum('billable_amount');
        $overtimeHours = (float) $counted->clone()->sum('overtime_hours');
        $pendingApprovalHours = (float) $job->timeEntries()->where('status', TimeEntry::STATUS_SUBMITTED)->sum('hours');

        $estimate = $job->estimates()->first();
        $estimateLaborHours = $estimate !== null ? EstimateBuilder::totalsFor($estimate)['laborHours'] : null;

        return [
            'estimatedHours' => round($estimatedHours, 2),
            'actualHours' => round($actualHours, 2),
            'remainingHours' => round(max(0, $estimatedHours - $actualHours), 2),
            'billableHours' => round($billableHours, 2),
            'overtimeHours' => round($overtimeHours, 2),
            'laborCost' => round($laborCost, 2),
            'billableAmount' => round($billableAmount, 2),
            'pendingApprovalHours' => round($pendingApprovalHours, 2),
            'estimateLaborHours' => $estimateLaborHours !== null ? round((float) $estimateLaborHours, 2) : null,
            'estimateVarianceHours' => $estimateLaborHours !== null
                ? round($actualHours - (float) $estimateLaborHours, 2)
                : null,
        ];
    }
}
