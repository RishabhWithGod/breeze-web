<?php

namespace App\Http\Controllers;

use App\Http\Resources\CrewShiftResource;
use App\Http\Resources\SchedulableJobResource;
use App\Models\CrewShift;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\TeamMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Crew scheduling: the calendar, and the queue of work still to be booked.
 *
 * Two screens over one idea. The calendar draws `job_schedules` — one block per
 * crew shift — and the unassigned queue is the complement: jobs with no shift on
 * it yet. Booking a job moves it from the second screen to the first, which is why
 * both live on the same controller.
 *
 * The visible window is derived from the query string, so a calendar someone has
 * paged to is a shareable URL rather than lost component state.
 */
class SchedulingController extends Controller
{
    /** How many unassigned jobs the calendar's strip shows before "view all". */
    private const STRIP_LIMIT = 3;

    /**
     * The scheduling calendar.
     *
     * `view` and `date` together define the window. `date` is any day inside it,
     * not necessarily the first — "today" is just today's date with no special
     * casing, which is what makes the Today button a plain link.
     */
    public function calendar(Request $request): Response
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['month', 'week'])],
            'date' => ['nullable', 'date'],
            'crew' => ['nullable', 'string', 'max:60'],
            'member' => ['nullable', 'integer', 'exists:team_members,id'],
        ]);

        $view = $filters['view'] ?? 'week';
        $anchor = $this->anchorDate($filters['date'] ?? null);
        [$from, $to] = $this->window($view, $anchor);

        $shifts = CrewShift::query()
            ->with(['job:id,name,client,location,job_type,priority', 'teamMember:id,name,initials,role'])
            ->between($from, $to)
            ->when(
                filled($filters['crew'] ?? null),
                fn ($query) => $query->where('crew', $filters['crew'])
            )
            ->when(
                filled($filters['member'] ?? null),
                fn ($query) => $query->where('team_member_id', $filters['member'])
            )
            ->get();

        return Inertia::render('Scheduling', [
            'view' => $view,
            'anchor' => $anchor->toDateString(),
            'today' => Carbon::today()->toDateString(),
            'periodLabel' => $view === 'month'
                ? $anchor->format('F Y')
                : $this->weekLabel($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            /* The grid itself, so the client never has to do date arithmetic. */
            'days' => $this->days($from, $to, $anchor, $view),
            'shifts' => CrewShiftResource::collection($shifts)->resolve(),
            'unassigned' => SchedulableJobResource::collection(
                Job::query()->with('foreman')->unscheduled()
                    ->sortedForScheduling('priority-desc')
                    ->take(self::STRIP_LIMIT)
                    ->get()
            )->resolve(),
            'unassignedTotal' => Job::query()->unscheduled()->count(),
            'crews' => $this->crews(),
            'members' => $this->members(),
            'filters' => [
                'crew' => $filters['crew'] ?? '',
                'member' => (string) ($filters['member'] ?? ''),
            ],
        ]);
    }

    /**
     * Jobs that still need a crew.
     *
     * Search, tabs, sorting and paging all run in the database against the query
     * string, matching every other index screen in the product.
     */
    public function unassigned(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['all', ...Job::TYPES])],
            'sort' => ['nullable', Rule::in(Job::SCHEDULING_SORTS)],
        ]);

        $type = $filters['type'] ?? 'all';
        $sort = $filters['sort'] ?? 'priority-desc';

        $jobs = Job::query()
            ->with('foreman')
            ->unscheduled()
            ->search($filters['search'] ?? null)
            ->when($type !== 'all', fn ($query) => $query->where('job_type', $type))
            ->sortedForScheduling($sort)
            ->paginate(config('takeoff.per_page'))
            ->withQueryString();

        return Inertia::render('SchedulingUnassigned', [
            'jobs' => SchedulableJobResource::collection($jobs),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'type' => $type,
                'sort' => $sort,
            ],
            /*
             * Counted without the type filter applied, so the tabs keep showing the
             * whole queue's shape rather than collapsing to the current tab.
             */
            'counts' => $this->typeCounts($filters['search'] ?? null),
            'crews' => $this->crews(),
            'members' => $this->members(),
            'today' => Carbon::today()->toDateString(),
        ]);
    }

    /**
     * Crew availability — who has room, who is full, and who is double-booked.
     *
     * The same window model as the calendar, so the two screens always agree about
     * which days are being counted. The heavy lifting is one grouped query per
     * question rather than a query per crew member.
     */
    public function availability(Request $request): Response
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['month', 'week'])],
            'date' => ['nullable', 'date'],
            'tab' => ['nullable', Rule::in(['overview', 'crews', 'conflicts'])],
        ]);

        $view = $filters['view'] ?? 'week';
        $tab = $filters['tab'] ?? 'overview';
        $anchor = $this->anchorDate($filters['date'] ?? null);
        [$from, $to] = $this->window($view, $anchor);

        $shifts = CrewShift::query()
            ->with(['job:id,name,client,job_type,priority', 'teamMember:id,name,initials,role'])
            ->between($from, $to)
            ->get();

        $crewLoad = $this->crewLoad($from, $to);
        $capacity = $crewLoad[0]['capacity'] ?? 0;
        $bookedHours = round((float) collect($crewLoad)->sum('hours'), 2);
        $totalCapacity = $capacity * count($crewLoad);

        return Inertia::render('SchedulingAvailability', [
            'view' => $view,
            'tab' => $tab,
            'anchor' => $anchor->toDateString(),
            'today' => Carbon::today()->toDateString(),
            'periodLabel' => $view === 'month'
                ? $anchor->format('F Y')
                : $this->weekLabel($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'members' => count($crewLoad),
                'bookedHours' => $bookedHours,
                'capacityHours' => $totalCapacity,
                'utilisation' => $totalCapacity > 0
                    ? (int) round($bookedHours / $totalCapacity * 100)
                    : 0,
                'availableHours' => round(max(0, $totalCapacity - $bookedHours), 2),
                'overbooked' => collect($crewLoad)->where('isOverbooked', true)->count(),
                'idle' => collect($crewLoad)->where('shifts', 0)->count(),
                'shifts' => $shifts->count(),
                /* A shift with nobody named still consumes a crew's day. */
                'unnamedShifts' => $shifts->whereNull('team_member_id')->count(),
            ],
            'availability' => $crewLoad,
            'assignments' => $this->assignmentsByMember($shifts),
            'crewTotals' => $this->crewTotals($shifts, $capacity),
            'conflicts' => $this->conflicts($shifts),
            'activity' => $this->schedulingActivity(),
            'members' => $this->members(),
            'crews' => $this->crews(),
        ]);
    }

    /**
     * Books a crew onto a job.
     *
     * `days` bookends the shift: a three-day job is three rows, so the calendar can
     * show it on each day and a crew can be changed on one of them without touching
     * the rest. Weekends are skipped rather than booked.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:work_jobs,id'],
            'team_member_id' => ['nullable', 'integer', 'exists:team_members,id'],
            'crew' => ['required', 'string', 'max:60'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_hours' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $job = Job::findOrFail($data['job_id']);
        $start = Carbon::parse($data['scheduled_date'])->startOfDay();
        $days = $data['days'] ?? 1;
        $booked = 0;

        // One transaction: a part-booked job would show on the calendar for some of
        // its days and in the unassigned queue for none of them.
        DB::transaction(function () use ($data, $job, $start, $days, $request, &$booked) {
            for ($offset = 0, $placed = 0; $placed < $days && $offset < $days + 10; $offset++) {
                $date = $start->copy()->addDays($offset);

                if ($date->isWeekend()) {
                    continue;
                }

                CrewShift::create([
                    'job_id' => $job->id,
                    'team_member_id' => $data['team_member_id'] ?? null,
                    'created_by' => $request->user()?->id,
                    'crew' => $data['crew'],
                    'scheduled_date' => $date->toDateString(),
                    'start_time' => $data['start_time'].':00',
                    'duration_hours' => $data['duration_hours'],
                    'status' => CrewShift::STATUS_SCHEDULED,
                    'notes' => $data['notes'] ?? null,
                ]);

                $placed++;
                $booked++;
            }

            /*
             * A booked job is a scheduled job. Draft and planning work that has just
             * been given a crew should not still read as unplanned on every other
             * screen — but work already under way keeps its own status.
             */
            if (in_array($job->status, ['draft', 'planning'], true)) {
                $job->changeStatus('scheduled');
            }

            if ($job->start_date === null) {
                $job->forceFill(['start_date' => $start->toDateString()])->saveQuietly();
            }

            $job->recordActivity(
                'scheduled',
                "Booked {$data['crew']} for {$booked} ".str('day')->plural($booked)." from {$start->format('M j, Y')}",
                ['crew' => $data['crew'], 'days' => $booked],
            );
        });

        return back()->with(
            'success',
            "{$job->name} scheduled — {$data['crew']} booked for {$booked} ".str('day')->plural($booked).'.'
        );
    }

    /** Moves a shift, or hands it to a different crew. */
    public function update(Request $request, CrewShift $schedule): RedirectResponse
    {
        $data = $request->validate([
            'team_member_id' => ['nullable', 'integer', 'exists:team_members,id'],
            'crew' => ['nullable', 'string', 'max:60'],
            'scheduled_date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'duration_hours' => ['nullable', 'numeric', 'min:0.5', 'max:24'],
            'status' => ['nullable', Rule::in(CrewShift::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $schedule->fill(array_filter([
            'team_member_id' => $data['team_member_id'] ?? null,
            'crew' => $data['crew'] ?? null,
            'scheduled_date' => $data['scheduled_date'] ?? null,
            'start_time' => isset($data['start_time']) ? $data['start_time'].':00' : null,
            'duration_hours' => $data['duration_hours'] ?? null,
            'status' => $data['status'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return back()->with('success', 'The shift was updated.');
    }

    /**
     * Removes a shift.
     *
     * A job whose last shift is removed goes back to the unassigned queue, which is
     * the whole point of "unassigned" being the absence of a booking.
     */
    public function destroy(CrewShift $schedule): RedirectResponse
    {
        $job = $schedule->job;
        $schedule->delete();

        $job?->recordActivity('unscheduled', 'A crew shift was removed from the calendar.');

        return back()->with('warning', 'The shift was removed from the calendar.');
    }

    /* ------------------------------------------------------------- internals */

    /** Any day inside the window; an unparseable value falls back to today. */
    private function anchorDate(?string $date): Carbon
    {
        return $date === null ? Carbon::today() : Carbon::parse($date)->startOfDay();
    }

    /**
     * First and last day drawn.
     *
     * A month view is padded out to whole weeks so the grid is always a rectangle —
     * the reference calendar shows the leading and trailing days rather than
     * leaving ragged holes.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $view, Carbon $anchor): array
    {
        if ($view === 'month') {
            return [
                $anchor->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY),
                $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY),
            ];
        }

        return [
            $anchor->copy()->startOfWeek(Carbon::SUNDAY),
            $anchor->copy()->endOfWeek(Carbon::SATURDAY),
        ];
    }

    /** "Oct 4 – 10, 2026", collapsing the month when both ends share one. */
    private function weekLabel(Carbon $from, Carbon $to): string
    {
        return $from->isSameMonth($to)
            ? $from->format('F j').' – '.$to->format('j, Y')
            : $from->format('M j').' – '.$to->format('M j, Y');
    }

    /**
     * Every cell in the grid, already labelled.
     *
     * Built here rather than in the browser so the two never disagree about which
     * day is "today" — the server's clock is the one the shifts were saved against.
     *
     * @return list<array<string, mixed>>
     */
    private function days(Carbon $from, Carbon $to, Carbon $anchor, string $view): array
    {
        $days = [];
        $today = Carbon::today();

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'dayOfMonth' => $date->day,
                'weekday' => $date->format('D'),
                'label' => $date->format('D, M j'),
                'isToday' => $date->isSameDay($today),
                'isWeekend' => $date->isWeekend(),
                // Month views pad into neighbouring months; those cells are dimmed.
                'isCurrentPeriod' => $view === 'month' ? $date->isSameMonth($anchor) : true,
            ];
        }

        return $days;
    }

    /**
     * Crew labels already in use, so the assign form offers real teams.
     *
     * @return list<string>
     */
    private function crews(): array
    {
        $known = CrewShift::query()
            ->reorder()
            ->distinct()
            ->orderBy('crew')
            ->pluck('crew')
            ->all();

        // Always offer the standard three, even on an empty calendar.
        return collect(['Team A', 'Team B', 'Team C'])
            ->merge($known)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Crew who can lead a shift.
     *
     * @return list<array<string, mixed>>
     */
    private function members(): array
    {
        return TeamMember::query()
            ->orderBy('name')
            ->get(['id', 'name', 'initials', 'role'])
            ->map(fn (TeamMember $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'initials' => $member->initials,
                'role' => $member->role,
            ])
            ->all();
    }

    /**
     * Hours booked per crew member across the window, for the availability panel.
     *
     * Aggregated in one grouped query rather than per member.
     *
     * @return list<array<string, mixed>>
     */
    private function crewLoad(Carbon $from, Carbon $to): array
    {
        /** @var Collection<int, object> $booked */
        $booked = CrewShift::query()
            ->reorder()
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('team_member_id')
            ->selectRaw('team_member_id, sum(duration_hours) as hours, count(*) as shifts')
            ->groupBy('team_member_id')
            ->get()
            ->keyBy('team_member_id');

        // Capacity is eight hours per working day in the window, so a month view
        // scales with its weeks. Counted by walking the range: Carbon 3 dropped
        // `diffInDaysFiltered`.
        $workingDays = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! $day->isWeekend()) {
                $workingDays++;
            }
        }

        $capacity = max(1, $workingDays) * 8;

        return TeamMember::query()
            ->orderBy('name')
            ->get(['id', 'name', 'initials', 'role'])
            ->map(function (TeamMember $member) use ($booked, $capacity) {
                $row = $booked->get($member->id);
                $hours = (float) ($row->hours ?? 0);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'initials' => $member->initials,
                    'role' => $member->role,
                    'hours' => $hours,
                    'shifts' => (int) ($row->shifts ?? 0),
                    'capacity' => $capacity,
                    // Clamped: an over-booked crew should read 100% on the bar and
                    // be flagged by `isOverbooked`, not overflow it.
                    'utilisation' => $capacity > 0 ? min(100, (int) round($hours / $capacity * 100)) : 0,
                    'isOverbooked' => $hours > $capacity,
                ];
            })
            ->all();
    }

    /**
     * Each crew member's shifts in the window, keyed by member id.
     *
     * Built from the shifts already loaded rather than re-queried per member.
     *
     * @param  Collection<int, CrewShift>  $shifts
     * @return array<int, list<array<string, mixed>>>
     */
    private function assignmentsByMember(Collection $shifts): array
    {
        return $shifts
            ->whereNotNull('team_member_id')
            ->sortBy([['scheduled_date', 'asc'], ['start_time', 'asc']])
            ->groupBy('team_member_id')
            ->map(fn (Collection $rows) => $rows->map(fn (CrewShift $shift) => [
                'id' => $shift->id,
                'jobId' => $shift->job_id,
                'jobName' => $shift->job?->name ?? 'Unknown job',
                'client' => $shift->job?->client,
                'crew' => $shift->crew,
                'date' => $shift->scheduled_date->toDateString(),
                'dayLabel' => $shift->scheduled_date->format('D, M j'),
                'startLabel' => $shift->startLabel(),
                'endLabel' => $shift->endLabel(),
                'durationHours' => (float) $shift->duration_hours,
                'status' => $shift->status,
            ])->values()->all())
            ->all();
    }

    /**
     * Hours and headcount per crew, for the part-to-whole breakdown.
     *
     * @param  Collection<int, CrewShift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function crewTotals(Collection $shifts, float $capacityPerMember): array
    {
        $total = (float) $shifts->sum('duration_hours');

        return $shifts
            ->groupBy('crew')
            ->map(fn (Collection $rows, string $crew) => [
                'crew' => $crew,
                'hours' => round((float) $rows->sum('duration_hours'), 2),
                'shifts' => $rows->count(),
                'members' => $rows->pluck('team_member_id')->filter()->unique()->count(),
                'jobs' => $rows->pluck('job_id')->unique()->count(),
                // Share of the booked total, so the segments sum to 100.
                'share' => $total > 0 ? round((float) $rows->sum('duration_hours') / $total * 100, 1) : 0.0,
                'capacity' => round($rows->pluck('team_member_id')->filter()->unique()->count() * $capacityPerMember, 2),
            ])
            ->sortByDesc('hours')
            ->values()
            ->all();
    }

    /**
     * Shifts that overlap in time for the same person on the same day.
     *
     * Two bookings on one day are normal — a morning job and an afternoon one. What
     * is not normal is the second starting before the first has finished, which is
     * a person who cannot be in both places. Only genuine overlaps are reported, so
     * the tab is empty when the schedule is sound.
     *
     * @param  Collection<int, CrewShift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function conflicts(Collection $shifts): array
    {
        $conflicts = [];

        foreach ($shifts->whereNotNull('team_member_id')->groupBy('team_member_id') as $rows) {
            $byDay = $rows->groupBy(fn (CrewShift $shift) => $shift->scheduled_date->toDateString());

            foreach ($byDay as $date => $sameDay) {
                $ordered = $sameDay->sortBy('start_time')->values();

                /*
                 * Every pair, not just neighbours. A long shift can overlap one that
                 * starts well after the shift between them — 8am–6pm clashes with a
                 * 4pm booking even when the 9am one in between does not — and
                 * comparing only adjacent pairs would miss exactly that.
                 */
                for ($first = 0; $first < $ordered->count(); $first++) {
                    $earlier = $ordered[$first];
                    $earlierEnd = Carbon::parse($earlier->start_time)
                        ->addMinutes((int) round(((float) $earlier->duration_hours) * 60));

                    for ($second = $first + 1; $second < $ordered->count(); $second++) {
                        $later = $ordered[$second];
                        $laterStart = Carbon::parse($later->start_time);

                        // Sorted by start time, so once one clears this shift's end
                        // every later one does too.
                        if ($laterStart->gte($earlierEnd)) {
                            break;
                        }

                        $member = $earlier->teamMember;

                        $conflicts[] = [
                            'id' => "{$earlier->id}-{$later->id}",
                            'date' => $date,
                            'dayLabel' => $earlier->scheduled_date->format('D, M j'),
                            'member' => $member ? [
                                'id' => $member->id,
                                'name' => $member->name,
                                'initials' => $member->initials,
                                'role' => $member->role,
                            ] : null,
                            'overlapMinutes' => (int) $laterStart->diffInMinutes($earlierEnd),
                            'first' => [
                                'id' => $earlier->id,
                                'jobId' => $earlier->job_id,
                                'jobName' => $earlier->job?->name ?? 'Unknown job',
                                'crew' => $earlier->crew,
                                'startLabel' => $earlier->startLabel(),
                                'endLabel' => $earlier->endLabel(),
                            ],
                            'second' => [
                                'id' => $later->id,
                                'jobId' => $later->job_id,
                                'jobName' => $later->job?->name ?? 'Unknown job',
                                'crew' => $later->crew,
                                'startLabel' => $later->startLabel(),
                                'endLabel' => $later->endLabel(),
                            ],
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * Recent scheduling decisions, for the activity panel.
     *
     * Read from the jobs' own activity trail, which `store()` and `destroy()`
     * already write to — so this cannot drift from what actually happened.
     *
     * @return list<array<string, mixed>>
     */
    private function schedulingActivity(int $limit = 6): array
    {
        return JobActivity::query()
            ->with('job:id,name')
            ->whereIn('type', ['scheduled', 'unscheduled'])
            ->latest('id')
            ->take($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->type,
                'jobId' => $row->job_id,
                'jobName' => $row->job?->name ?? 'Unknown job',
                'description' => $row->description,
                'at' => $row->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * Queue size per type, honouring the search but not the type tab.
     *
     * @return array{all: int, residential: int, commercial: int, industrial: int}
     */
    private function typeCounts(?string $search): array
    {
        $rows = Job::query()
            ->reorder()
            ->unscheduled()
            ->search($search)
            ->selectRaw('job_type, count(*) as total')
            ->groupBy('job_type')
            ->pluck('total', 'job_type');

        return [
            'all' => (int) $rows->sum(),
            'residential' => (int) ($rows['residential'] ?? 0),
            'commercial' => (int) ($rows['commercial'] ?? 0),
            'industrial' => (int) ($rows['industrial'] ?? 0),
        ];
    }
}
