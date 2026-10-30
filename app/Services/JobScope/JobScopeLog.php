<?php

namespace App\Services\JobScope;

use App\Events\JobScopeChanged;
use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\JobScopeChange;
use App\Notifications\JobScopeChangedNotice;
use App\Services\Billing\ActualCostInvoiceSync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The one place a change to a job's scope is remembered. Every writer that
 * moves what a job is priced or billed at ends up here, so the same three
 * things always follow: a versioned entry in the job's timeline, a broadcast
 * so every open screen and phone refreshes, and the job's draft invoices
 * brought in line with the new latest.
 */
class JobScopeLog
{
    public function __construct(private readonly ActualCostInvoiceSync $invoices) {}

    /**
     * @param  array{estimate_id?: ?int, item_changes?: ?array, totals_before?: ?array, totals_after?: ?array, amount?: float|int, source?: string, except_invoice_id?: ?int, resync?: bool}  $details
     */
    public function record(?Job $job, string $kind, string $summary, array $details = []): JobScopeChange
    {
        $change = DB::transaction(function () use ($job, $kind, $summary, $details) {
            $estimateId = $details['estimate_id'] ?? null;

            $version = (int) JobScopeChange::query()
                ->when(
                    $job !== null,
                    fn ($q) => $q->where('job_id', $job->id),
                    fn ($q) => $q->whereNull('job_id')->where('estimate_id', $estimateId),
                )
                ->max('version') + 1;

            return JobScopeChange::create([
                'job_id' => $job?->id,
                'estimate_id' => $estimateId,
                'version' => $version,
                'kind' => $kind,
                'source' => $details['source'] ?? ChangeSource::current(),
                'user_id' => Auth::id(),
                'summary' => mb_substr($summary, 0, 255),
                'item_changes' => $details['item_changes'] ?? null,
                'totals_before' => $details['totals_before'] ?? null,
                'totals_after' => $details['totals_after'] ?? null,
                'amount' => $details['amount'] ?? 0,
            ]);
        });

        if ($job !== null) {
            if ($details['resync'] ?? true) {
                // The latest scope is what the bill shows — except an invoice the change was made on, which already says it.
                $this->invoices->sync($job, $details['except_invoice_id'] ?? null);
            }

            event(new JobScopeChanged($job, $change));
            $this->notifyManager($job, $change);
        }

        return $change;
    }

    /** The job's project manager hears about a change someone else made away from the office. */
    private function notifyManager(Job $job, JobScopeChange $change): void
    {
        if (! in_array($change->source, [ChangeSource::FIELD, ChangeSource::MOBILE, ChangeSource::INVOICE], true)) {
            return;
        }

        $manager = $job->activeAssignments()
            ->where('role', JobAssignment::ROLE_PROJECT_MANAGER)
            ->with('user')
            ->first()
            ?->user;

        if ($manager !== null && $manager->id !== $change->user_id) {
            $manager->notify(new JobScopeChangedNotice($job, $change));
        }
    }
}
