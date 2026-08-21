<?php

namespace App\Services\Dashboard;

use App\Models\Job;
use App\Models\JobStatusChange;
use Illuminate\Support\Carbon;

/**
 * The dashboard's "Monthly Performance" series: of the jobs that reached
 * `completed` in a given month, the percentage that did so on or before
 * their `end_date`. A job with no `end_date` had no deadline to miss, so it
 * counts as on time.
 *
 * A rolling 12-month window ending on the current month, not a fixed
 * Jan–Dec span — so the series is always "the last year", not one year's
 * numbers relabelled forever.
 */
class JobPerformanceCalculator
{
    /**
     * @return list<array{month: string, value: int|null, count: int}>
     */
    public function series(?Carbon $through = null): array
    {
        $through = ($through ?? now())->copy()->startOfMonth();
        $months = collect(range(11, 0))->map(fn (int $offset) => $through->copy()->subMonths($offset));

        $completions = JobStatusChange::query()
            ->where('to_status', 'completed')
            ->whereBetween('created_at', [$months->first(), $through->copy()->endOfMonth()])
            ->orderBy('job_id')
            ->orderByDesc('created_at')
            ->get()
            // A job that bounced out of and back into `completed` only counts
            // once, on its most recent completion.
            ->unique('job_id');

        $jobs = Job::query()->whereIn('id', $completions->pluck('job_id'))->get(['id', 'end_date'])->keyBy('id');

        return $months->map(function (Carbon $month) use ($completions, $jobs) {
            $inMonth = $completions->filter(
                fn (JobStatusChange $change) => $change->created_at->isSameMonth($month) && $change->created_at->isSameYear($month)
            );
            $count = $inMonth->count();

            $onTime = $inMonth->filter(function (JobStatusChange $change) use ($jobs) {
                $deadline = $jobs->get($change->job_id)?->end_date;

                return $deadline === null || $change->created_at->lte($deadline->copy()->endOfDay());
            })->count();

            return [
                'month' => $month->format('M Y'),
                // Null, not 0, when nothing completed — there is no rate to report.
                'value' => $count === 0 ? null : (int) round($onTime / $count * 100),
                'count' => $count,
            ];
        })->values()->all();
    }
}
