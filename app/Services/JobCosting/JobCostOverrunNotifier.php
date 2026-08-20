<?php

namespace App\Services\JobCosting;

use App\Models\Job;
use App\Models\User;
use App\Notifications\JobCostOverrun;
use Illuminate\Support\Facades\Notification;

/**
 * The one place that decides "is this job over budget right now, and if so,
 * who should hear about it" — called after the two real moments that can
 * newly make that true: a time entry being approved, and an actual
 * material/equipment/other cost being logged.
 */
class JobCostOverrunNotifier
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function __construct(private readonly JobCostSummary $costSummary) {}

    public function checkAndNotify(Job $job): void
    {
        $summary = $this->costSummary->for($job);

        if (! $summary['isOverBudget']) {
            return;
        }

        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        Notification::send(
            $managers,
            new JobCostOverrun($job, $summary['overrunReason'], $summary['overrunAmount'], $summary['overrunPct']),
        );
    }
}
