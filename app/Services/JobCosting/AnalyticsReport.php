<?php

namespace App\Services\JobCosting;

use App\Models\AnalyticsSetting;
use App\Models\Invoice;
use App\Models\JobCostEntry;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The read models behind the Analytics tabs that `JobCostSummary` (one job's
 * estimated-vs-actual) does not cover: the monthly trend, labor by person and
 * role, invoice aging, and the alert list. Every method takes the already
 * filtered jobs, so each tab honours the same filters as the overview.
 *
 * The trend and the labor tab read logged time entries directly, so a
 * manager's saved "journeyman hours" total (an override of one person's raw
 * hours) is not reflected there — both are labelled "from logged time".
 */
class AnalyticsReport
{
    public function __construct(private readonly JobCostSummary $costSummary) {}

    /**
     * Monthly billed, collected and logged cost (labor at the project's rate,
     * plus manually logged material/equipment/other costs).
     *
     * @param  Collection<int, \App\Models\Job>  $jobs
     * @return list<array{month: string, label: string, billed: float, collected: float, cost: float, net: float}>
     */
    public function trend(Collection $jobs, ?string $from, ?string $to, ?int $teamMemberId): array
    {
        $end = $to ? Carbon::parse($to)->endOfMonth() : now()->endOfMonth();
        $start = $from ? Carbon::parse($from)->startOfMonth() : $end->copy()->subMonths(11)->startOfMonth();
        if ($start->diffInMonths($end) > 23) {
            $start = $end->copy()->subMonths(23)->startOfMonth();
        }

        $months = [];
        for ($cursor = $start->copy(); $cursor <= $end; $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = [
                'month' => $cursor->format('Y-m'),
                'label' => $cursor->format('M y'),
                'billed' => 0.0, 'collected' => 0.0, 'cost' => 0.0, 'net' => 0.0,
            ];
        }

        $ids = $jobs->pluck('id');
        $rates = $this->ratesByJob($jobs);

        TimeEntry::query()
            ->whereIn('job_id', $ids)
            ->where('status', '!=', TimeEntry::STATUS_LOCKED)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($teamMemberId, fn ($q) => $q->where('team_member_id', $teamMemberId))
            ->get(['job_id', 'date', 'hours'])
            ->each(function (TimeEntry $entry) use (&$months, $rates) {
                $key = $entry->date->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['cost'] += (float) $entry->hours * ($rates[$entry->job_id] ?? 0.0);
                }
            });

        JobCostEntry::query()
            ->whereIn('job_id', $ids)
            ->whereBetween('incurred_on', [$start->toDateString(), $end->toDateString()])
            ->get(['incurred_on', 'amount'])
            ->each(function (JobCostEntry $entry) use (&$months) {
                $key = $entry->incurred_on->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['cost'] += (float) $entry->amount;
                }
            });

        Invoice::query()
            ->whereIn('job_id', $ids)
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->whereNotNull('invoice_date')
            ->get(['invoice_date', 'total', 'paid_amount', 'paid_at'])
            ->each(function (Invoice $invoice) use (&$months, $start, $end) {
                $billedKey = $invoice->invoice_date->format('Y-m');
                if (isset($months[$billedKey])) {
                    $months[$billedKey]['billed'] += (float) $invoice->total;
                }

                $paidOn = $invoice->paid_at ?? $invoice->invoice_date;
                $paidKey = $paidOn->format('Y-m');
                if ((float) $invoice->paid_amount > 0 && isset($months[$paidKey])) {
                    $months[$paidKey]['collected'] += (float) $invoice->paid_amount;
                }
            });

        return collect($months)->map(fn (array $m) => [
            ...$m,
            'billed' => round($m['billed'], 2),
            'collected' => round($m['collected'], 2),
            'cost' => round($m['cost'], 2),
            'net' => round($m['billed'] - $m['cost'], 2),
        ])->values()->all();
    }

    /**
     * Hours by person and by role over the window, with overtime and billable share.
     *
     * @param  Collection<int, \App\Models\Job>  $jobs
     * @return array{people: list<array<string, mixed>>, roles: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function labor(Collection $jobs, ?string $from, ?string $to, ?int $teamMemberId, bool $canViewCosts): array
    {
        $rates = $this->ratesByJob($jobs);

        $people = TimeEntry::query()
            ->whereIn('job_id', $jobs->pluck('id'))
            ->where('status', '!=', TimeEntry::STATUS_LOCKED)
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            ->when($teamMemberId, fn ($q) => $q->where('team_member_id', $teamMemberId))
            ->with(['teamMember', 'user'])
            ->get()
            ->groupBy('user_id')
            ->map(function (Collection $entries) use ($rates, $canViewCosts) {
                $first = $entries->first();
                $hours = (float) $entries->sum('hours');
                $overtime = (float) $entries->sum('overtime_hours');
                $billable = (float) $entries->where('billable', true)->sum('hours');

                return [
                    'name' => $first->teamMember?->name ?? $first->user?->name ?? 'Unknown',
                    'role' => $first->teamMember?->role ?? $first->user?->role ?? 'Unassigned',
                    'hours' => round($hours, 2),
                    'regularHours' => round((float) $entries->sum('regular_hours'), 2),
                    'overtimeHours' => round($overtime, 2),
                    'overtimePct' => $hours > 0 ? round($overtime / $hours * 100, 1) : 0.0,
                    'billableHours' => round($billable, 2),
                    'billablePct' => $hours > 0 ? round($billable / $hours * 100, 1) : 0.0,
                    'jobs' => $entries->pluck('job_id')->unique()->count(),
                    'cost' => $canViewCosts
                        ? round($entries->sum(fn (TimeEntry $e) => (float) $e->hours * ($rates[$e->job_id] ?? 0.0)), 2)
                        : null,
                ];
            })
            ->sortByDesc('hours')
            ->values();

        $roles = $people->groupBy('role')->map(function (Collection $group, string $role) use ($canViewCosts) {
            $hours = (float) $group->sum('hours');
            $overtime = (float) $group->sum('overtimeHours');

            return [
                'role' => $role,
                'people' => $group->count(),
                'hours' => round($hours, 2),
                'overtimeHours' => round($overtime, 2),
                'overtimePct' => $hours > 0 ? round($overtime / $hours * 100, 1) : 0.0,
                'cost' => $canViewCosts ? round((float) $group->sum('cost'), 2) : null,
            ];
        })->sortByDesc('hours')->values();

        $hours = (float) $people->sum('hours');
        $overtime = (float) $people->sum('overtimeHours');
        $billable = (float) $people->sum('billableHours');

        return [
            'people' => $people->all(),
            'roles' => $roles->all(),
            'totals' => [
                'hours' => round($hours, 2),
                'overtimeHours' => round($overtime, 2),
                'overtimePct' => $hours > 0 ? round($overtime / $hours * 100, 1) : 0.0,
                'billableHours' => round($billable, 2),
                'billablePct' => $hours > 0 ? round($billable / $hours * 100, 1) : 0.0,
            ],
        ];
    }

    /**
     * Outstanding invoices bucketed by how far past due they are, plus the
     * biggest balances and the clients that owe the most.
     *
     * @param  Collection<int, \App\Models\Job>  $jobs
     * @return array<string, mixed>
     */
    public function billing(Collection $jobs, AnalyticsSetting $settings): array
    {
        $today = now()->startOfDay();
        $buckets = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_plus' => 0.0];

        $open = $this->openInvoices($jobs)->map(function (Invoice $invoice) use ($today, $settings, &$buckets) {
            $balance = round((float) $invoice->total - (float) $invoice->paid_amount, 2);
            $due = $invoice->due_date;
            $daysOverdue = $due !== null ? max(0, (int) $due->startOfDay()->diffInDays($today, false)) : 0;
            $bucket = match (true) {
                $daysOverdue === 0 => 'current',
                $daysOverdue <= 30 => 'd1_30',
                $daysOverdue <= 60 => 'd31_60',
                default => 'd61_plus',
            };
            $buckets[$bucket] += $balance;

            return [
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'client' => $invoice->client,
                'jobId' => $invoice->job_id,
                'jobName' => $invoice->job?->name,
                'dueDate' => $due?->toDateString(),
                'balance' => $balance,
                'daysOverdue' => $daysOverdue,
                'isOverdue' => $daysOverdue > $settings->overdue_grace_days,
            ];
        });

        return [
            'aging' => collect($buckets)->map(fn (float $v) => round($v, 2))->all(),
            'overdueTotal' => round((float) $open->where('isOverdue', true)->sum('balance'), 2),
            'overdueCount' => $open->where('isOverdue', true)->count(),
            'invoices' => $open->sortByDesc('balance')->take(10)->values()->all(),
            'byClient' => $open->groupBy(fn (array $i) => $i['client'] ?: 'No client')
                ->map(fn (Collection $g, string $client) => [
                    'client' => $client,
                    'invoices' => $g->count(),
                    'balance' => round((float) $g->sum('balance'), 2),
                ])->sortByDesc('balance')->take(8)->values()->all(),
        ];
    }

    /**
     * Everything worth a manager's attention, worst first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  `JobCostSummary::for()` rows
     * @param  Collection<int, \App\Models\Job>  $jobs
     * @return list<array{id: string, type: string, severity: string, jobId: ?int, jobName: ?string, title: string, detail: string}>
     */
    public function alerts(Collection $rows, Collection $jobs, AnalyticsSetting $settings, bool $canViewCosts): array
    {
        $alerts = collect();

        foreach ($rows as $row) {
            if ($row['isOverBudget']) {
                $alerts->push($this->alert('overrun', 'danger', $row, 'Over budget', ($row['overrunPct'] !== null ? "{$row['overrunPct']}% over estimate" : 'Exceeding estimate')));
            } elseif ($row['estimatedLaborHours'] > 0
                && $row['actualLaborHours'] / $row['estimatedLaborHours'] * 100 >= $settings->hours_warning_pct) {
                $used = round($row['actualLaborHours'] / $row['estimatedLaborHours'] * 100, 1);
                $alerts->push($this->alert('hours_warning', 'warning', $row, 'Nearing hours budget', "{$used}% of estimated hours used"));
            }

            if ($canViewCosts) {
                if ($row['revenue'] > 0 && $row['marginPct'] !== null && $row['marginPct'] < $settings->low_margin_pct) {
                    $alerts->push($this->alert('low_margin', 'warning', $row, 'Low margin', "{$row['marginPct']}% margin (target {$settings->low_margin_pct}%)"));
                }
                if ($row['jobStatus'] === \App\Models\Job::STATUS_COMPLETED && $row['unbilled'] > 0) {
                    $alerts->push($this->alert('unbilled', 'warning', $row, 'Completed but unbilled', '$'.number_format($row['unbilled'], 2).' not yet invoiced'));
                }
            }
        }

        if ($canViewCosts) {
            $jobNames = $jobs->keyBy('id');
            $this->openInvoices($jobs)
                ->filter(fn (Invoice $i) => $i->due_date !== null
                    && $i->due_date->copy()->addDays($settings->overdue_grace_days)->lt(now()->startOfDay()))
                ->groupBy('job_id')
                ->each(function (Collection $invoices, $jobId) use ($alerts, $jobNames) {
                    $balance = $invoices->sum(fn (Invoice $i) => (float) $i->total - (float) $i->paid_amount);
                    $alerts->push([
                        'id' => "overdue-{$jobId}",
                        'type' => 'overdue_invoice',
                        'severity' => 'danger',
                        'jobId' => (int) $jobId,
                        'jobName' => $jobNames->get($jobId)?->name,
                        'title' => 'Overdue invoice'.($invoices->count() > 1 ? 's' : ''),
                        'detail' => '$'.number_format($balance, 2).' past due across '.$invoices->count().' invoice'.($invoices->count() > 1 ? 's' : ''),
                    ]);
                });
        }

        TimeEntry::query()
            ->whereIn('job_id', $jobs->pluck('id'))
            ->whereNotNull('start_time')
            ->whereNull('end_time')
            ->with('job:id,name')
            ->get()
            ->each(function (TimeEntry $entry) use ($alerts, $settings) {
                $startedAt = Carbon::parse($entry->date->format('Y-m-d').' '.$entry->start_time);
                $running = (int) $startedAt->diffInHours(now());
                if ($running >= $settings->stale_timer_hours) {
                    $alerts->push([
                        'id' => "timer-{$entry->id}",
                        'type' => 'missing_checkout',
                        'severity' => 'warning',
                        'jobId' => $entry->job_id,
                        'jobName' => $entry->job?->name,
                        'title' => 'Missing check-out',
                        'detail' => "Timer running for {$running}h since ".$startedAt->format('M j, g:i A'),
                    ]);
                }
            });

        return $alerts
            ->sortBy(fn (array $a) => $a['severity'] === 'danger' ? 0 : 1)
            ->values()
            ->all();
    }

    /** @return Collection<int, Invoice> sent (not draft, not fully paid) invoices on the given jobs */
    private function openInvoices(Collection $jobs): Collection
    {
        return Invoice::query()
            ->whereIn('job_id', $jobs->pluck('id'))
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->whereColumn('paid_amount', '<', 'total')
            ->with('job:id,name')
            ->get();
    }

    /** @return array<int, float> job id => effective labor rate */
    private function ratesByJob(Collection $jobs): array
    {
        return $this->costSummary->laborRates($jobs);
    }

    /** @param  array<string, mixed>  $row */
    private function alert(string $type, string $severity, array $row, string $title, string $detail): array
    {
        return [
            'id' => "{$type}-{$row['jobId']}",
            'type' => $type,
            'severity' => $severity,
            'jobId' => $row['jobId'],
            'jobName' => $row['jobName'],
            'title' => $title,
            'detail' => $detail,
        ];
    }
}
