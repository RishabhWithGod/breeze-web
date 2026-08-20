<?php

namespace App\Console\Commands;

use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TimeEntryStatusChanged;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Reminds managers about time entries that have sat submitted too long.
 *
 * Deliberately narrow: one reminder per run, for entries at least a day old,
 * so a manager gets nudged once a day rather than on every scheduler tick.
 */
class SendTimeApprovalReminders extends Command
{
    protected $signature = 'time-tracking:send-approval-reminders';

    protected $description = 'Notify managers about time entries waiting more than a day for approval';

    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function handle(): int
    {
        $overdue = TimeEntry::query()
            ->where('status', TimeEntry::STATUS_SUBMITTED)
            ->where('submitted_at', '<=', now()->subDay())
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('Nothing waiting long enough to remind about.');

            return self::SUCCESS;
        }

        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->get();

        if ($managers->isEmpty()) {
            $this->warn('No managers to notify.');

            return self::SUCCESS;
        }

        foreach ($overdue as $entry) {
            Notification::send($managers, new TimeEntryStatusChanged($entry, TimeEntryStatusChanged::SUBMITTED));
        }

        $this->info("Reminded {$managers->count()} manager(s) about {$overdue->count()} overdue entry/entries.");

        return self::SUCCESS;
    }
}
