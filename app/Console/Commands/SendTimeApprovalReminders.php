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

        $reminded = 0;

        // Each company's managers hear only about their own company's time.
        foreach ($overdue->load('user:id,company_id')->groupBy(fn (TimeEntry $entry) => $entry->user?->company_id ?? 0) as $companyId => $entries) {
            $managers = User::query()
                ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
                ->when(
                    $companyId !== 0,
                    fn ($query) => $query->where('company_id', $companyId),
                    fn ($query) => $query->whereNull('company_id'),
                )
                ->get();

            if ($managers->isEmpty()) {
                continue;
            }

            foreach ($entries as $entry) {
                Notification::send($managers, new TimeEntryStatusChanged($entry, TimeEntryStatusChanged::SUBMITTED));
                $reminded++;
            }
        }

        if ($reminded === 0) {
            $this->warn('No managers to notify.');

            return self::SUCCESS;
        }

        $this->info("Sent {$reminded} reminder(s) about overdue entries.");

        return self::SUCCESS;
    }
}
