<?php

namespace App\Services\TimeTracking;

use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

/**
 * The team's weekly timesheet — one row per person, one column per day.
 *
 * `TimesheetWeekBuilder` answers "how was my week"; this answers "how was
 * everyone's week", the view a manager needs on the Time Log screen. Both
 * read the same `time_entries` rows, so the two can never disagree.
 */
class TeamTimesheetBuilder
{
    /**
     * @param  array{job?: int|null, userId?: int|null}  $filters  `userId` restricts
     *         the grid to one person — used for anyone who cannot see the whole crew.
     * @return array{weekStart: string, weekEnd: string, days: list<array<string, mixed>>, rows: list<array<string, mixed>>, total: float}
     */
    public function build(Carbon $anyDayInWeek, array $filters = []): array
    {
        $weekStart = $anyDayInWeek->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $anyDayInWeek->copy()->endOfWeek(Carbon::SUNDAY);

        $entries = TimeEntry::query()
            ->with(['teamMember', 'user'])
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNotIn('status', [TimeEntry::STATUS_REJECTED, TimeEntry::STATUS_LOCKED])
            ->when(! empty($filters['job']), fn ($query) => $query->where('job_id', $filters['job']))
            ->when(! empty($filters['userId']), fn ($query) => $query->where('user_id', $filters['userId']))
            ->get();

        // Grouped by `user_id`, never `team_member_id`: every entry has a real
        // owning user (NOT NULL), while `team_member_id` is nullable — grouping
        // by the latter when present would split one person across two rows
        // the moment they have even a single entry recorded before a team
        // member link existed.
        $byPerson = $entries->groupBy(fn (TimeEntry $entry) => $entry->user_id);

        $days = [];
        for ($cursor = $weekStart->copy(); $cursor->lessThanOrEqualTo($weekEnd); $cursor->addDay()) {
            $days[] = ['date' => $cursor->toDateString(), 'label' => $cursor->format('D n/j')];
        }

        $rows = $byPerson->map(function ($personEntries) use ($days) {
            /** @var TimeEntry $first */
            $first = $personEntries->first();
            $byDate = $personEntries->groupBy(fn (TimeEntry $entry) => $entry->date->toDateString());

            return [
                'person' => [
                    'id' => $first->user_id,
                    'name' => $first->teamMember?->name ?? $first->user?->name ?? 'Unknown',
                ],
                'days' => array_map(
                    fn (array $day) => round((float) $byDate->get($day['date'], collect())->sum('hours'), 2),
                    $days,
                ),
                'total' => round((float) $personEntries->sum('hours'), 2),
            ];
        })
            ->sortBy(fn (array $row) => $row['person']['name'])
            ->values()
            ->all();

        return [
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'days' => $days,
            'rows' => $rows,
            'total' => round((float) $entries->sum('hours'), 2),
        ];
    }
}
