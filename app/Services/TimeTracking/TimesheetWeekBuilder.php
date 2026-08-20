<?php

namespace App\Services\TimeTracking;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The 7-day timesheet grid for one person.
 *
 * Every cell — including which day is "today" — is computed server-side, the
 * same reasoning `SchedulingController::days()` documents for the crew
 * calendar: the browser's clock and the server's must never be the two places
 * that can disagree about which day is which.
 */
class TimesheetWeekBuilder
{
    /** @return array{weekStart: string, weekEnd: string, days: list<array<string, mixed>>, totals: array<string, float>} */
    public function build(User $user, Carbon $anyDayInWeek): array
    {
        $weekStart = $anyDayInWeek->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $anyDayInWeek->copy()->endOfWeek(Carbon::SUNDAY);

        $entries = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNotIn('status', [TimeEntry::STATUS_REJECTED, TimeEntry::STATUS_LOCKED])
            ->get();

        $byDate = $entries->groupBy(fn (TimeEntry $entry) => $entry->date->toDateString());

        $days = [];
        $totals = ['regular' => 0.0, 'overtime' => 0.0, 'total' => 0.0, 'billable' => 0.0];

        for ($cursor = $weekStart->copy(); $cursor->lessThanOrEqualTo($weekEnd); $cursor->addDay()) {
            $dateKey = $cursor->toDateString();
            $dayEntries = $byDate->get($dateKey, collect());

            $regular = (float) $dayEntries->sum('regular_hours');
            $overtime = (float) $dayEntries->sum('overtime_hours');
            $total = (float) $dayEntries->sum('hours');
            $billable = (float) $dayEntries->where('billable', true)->sum('hours');

            $days[] = [
                'date' => $dateKey,
                'label' => $cursor->format('D'),
                'isToday' => $cursor->isToday(),
                'isWeekend' => $cursor->isWeekend(),
                'regular' => round($regular, 2),
                'overtime' => round($overtime, 2),
                'total' => round($total, 2),
                'billable' => round($billable, 2),
                'entryCount' => $dayEntries->count(),
            ];

            $totals['regular'] += $regular;
            $totals['overtime'] += $overtime;
            $totals['total'] += $total;
            $totals['billable'] += $billable;
        }

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'days' => $days,
            'totals' => array_map(fn (float $value) => round($value, 2), $totals),
        ];
    }
}
