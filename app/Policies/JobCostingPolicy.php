<?php

namespace App\Policies;

use App\Models\JobCostEntry;
use App\Models\User;

/**
 * Who may do what with Job Costing.
 *
 * Viewing the dashboard/detail screens is open to anyone signed in — the
 * same openness Jobs and Estimates already have, neither of which has a view
 * restriction. Dollar figures are a separate concern: every screen here
 * gates them with the existing `viewJobCosts` ability on `TimeEntry`
 * (already used by `JobController::show()`/`TimeTrackingReportController`),
 * so this module never invents a second cost-visibility rule that could
 * disagree with the first. Only a Project Manager/Admin/Owner may record an
 * actual material/equipment/other cost or delete one.
 */
class JobCostingPolicy
{
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function manage(User $user): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    public function deleteEntry(User $user, JobCostEntry $entry): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user): array
    {
        return [
            'manage' => $this->manage($user),
        ];
    }

    /* ------------------------------------------------------------- internals */

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
