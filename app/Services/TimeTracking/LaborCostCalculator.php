<?php

namespace App\Services\TimeTracking;

use App\Models\TimeEntry;
use App\Models\TimeTrackingSetting;

/**
 * Resolves the rates for an entry and snapshots the cost onto it.
 *
 * The rate comes from the person's own `TeamMember` record when they have one
 * set, else the company default in `TimeTrackingSetting`. Snapshotting at save
 * time means a later rate change never rewrites a past entry's cost — the
 * number an approver saw is the number that stays true.
 */
class LaborCostCalculator
{
    public function apply(TimeEntry $entry, TimeTrackingSetting $settings): void
    {
        $teamMember = $entry->teamMember;

        $billableRate = $teamMember?->billable_rate !== null
            ? (float) $teamMember->billable_rate
            : ($settings->default_billable_rate !== null ? (float) $settings->default_billable_rate : null);

        $costRate = $teamMember?->cost_rate !== null
            ? (float) $teamMember->cost_rate
            : ($settings->default_cost_rate !== null ? (float) $settings->default_cost_rate : null);

        $entry->billable_rate = $billableRate;
        $entry->cost_rate = $costRate;
        $entry->labor_cost = $costRate !== null ? round((float) $entry->hours * $costRate, 2) : null;
        $entry->billable_amount = ($entry->billable && $billableRate !== null)
            ? round((float) $entry->hours * $billableRate, 2)
            : 0.0;
    }
}
