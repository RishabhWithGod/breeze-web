<?php

namespace App\Services\Scheduling;

use App\Models\Job;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The crew calendar, worked out from the jobs themselves.
 *
 * Nothing is booked onto it. A job belongs to a crew (its team) and runs between
 * its start and end dates, so the calendar simply draws that: one block per crew
 * per working day the job covers. A job with no tasks has nothing to draw, so it
 * is listed beside the calendar instead — the only thing left to do is break it
 * into tasks.
 */
class CrewBoard
{
    /** Jobs with no crew shown beside the calendar. */
    private const UNASSIGNED_LIMIT = 30;

    /** @return array<string, mixed> */
    public function build(User $user, string $view, Carbon $anchor): array
    {
        [$from, $to] = $this->window($view, $anchor);

        // A job is drawn once it has tasks: a job that has only been raised, with
        // nothing broken out yet, is unassigned and is listed beside the calendar.
        $jobs = Job::query()
            ->ownedBy($user)
            ->active()
            ->with(['schedule', 'tasks:id,job_id,estimated_hours,foreman_id', 'tasks.foreman:id,team_id'])
            ->whereHas('tasks')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $blocks = $this->blocks($jobs, $from, $to);

        $unassigned = Job::query()
            ->ownedBy($user)
            ->with('tasks:id,job_id,estimated_hours')
            ->withoutCrew()
            ->sortedForScheduling('start-desc');

        return [
            'view' => $view,
            'label' => $this->label($view, $from, $to, $anchor),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $this->days($from, $to, $anchor, $view),
            'crews' => $this->crews($blocks),
            'blocks' => $blocks,
            'unassigned' => (clone $unassigned)
                ->take(self::UNASSIGNED_LIMIT)
                ->get()
                ->map(fn (Job $job) => [
                    'id' => $job->id,
                    'name' => $job->name,
                    'client' => $job->client,
                    'location' => $job->location,
                    'type' => $job->job_type,
                    'priority' => $job->priority,
                    'hours' => $this->hours($job),
                ])
                ->all(),
            'unassignedTotal' => (clone $unassigned)->count(),
        ];
    }

    /**
     * First and last day drawn. Weeks run Monday to Sunday, and a month is padded
     * out to whole weeks so its grid is always a rectangle.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $view, Carbon $anchor): array
    {
        return match ($view) {
            'day' => [$anchor->copy(), $anchor->copy()],
            'month' => [
                $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
            ],
            default => [
                $anchor->copy()->startOfWeek(Carbon::MONDAY),
                $anchor->copy()->endOfWeek(Carbon::SUNDAY),
            ],
        };
    }

    private function label(string $view, Carbon $from, Carbon $to, Carbon $anchor): string
    {
        return match ($view) {
            'day' => $anchor->format('l, M j, Y'),
            'month' => $anchor->format('F Y'),
            default => $from->format('M j').' – '.$to->format('M j, Y'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function days(Carbon $from, Carbon $to, Carbon $anchor, string $view): array
    {
        $days = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->format('D'),
                'monthDay' => $date->format('M j'),
                'dayOfMonth' => $date->day,
                'isToday' => $date->isToday(),
                'isWeekend' => $date->isWeekend(),
                'isCurrentPeriod' => $view === 'month' ? $date->isSameMonth($anchor) : true,
            ];
        }

        return $days;
    }

    /**
     * One block per crew per working day a job covers inside the window.
     *
     * A crew with two jobs on the same day is double-booked, so every block on
     * that day says so — the calendar shows a clash rather than hiding one job
     * behind the other.
     *
     * @param  Collection<int, Job>  $jobs
     * @return list<array<string, mixed>>
     */
    private function blocks($jobs, Carbon $from, Carbon $to): array
    {
        $blocks = [];

        foreach ($jobs as $job) {
            $workingDays = $job->schedule?->working_days ?: [1, 2, 3, 4, 5];
            // The job's own dates, else the ones its schedule was laid out with.
            $startsOn = $job->start_date ?? $job->schedule?->starts_on;
            $endsOn = $job->end_date ?? $job->schedule?->ends_on ?? $startsOn;

            if ($startsOn === null) {
                continue;
            }

            $start = Carbon::parse($startsOn)->startOfDay();
            $end = Carbon::parse($endsOn)->startOfDay();
            $time = $this->timeRange($job);
            $crewId = $this->crewId($job);

            // `max`/`min` hand back one of the two instances themselves, so the
            // window's own dates are copied before the loop steps through them.
            $first = $start->max($from->copy());
            $last = $end->min($to->copy());

            for ($date = $first->copy(); $date->lte($last); $date->addDay()) {
                if (! in_array($date->dayOfWeekIso, $workingDays, true)) {
                    continue;
                }

                $blocks[] = [
                    'key' => $job->id.'-'.$date->toDateString(),
                    'jobId' => $job->id,
                    'name' => $job->name,
                    'location' => $job->location,
                    'crewId' => $crewId,
                    'date' => $date->toDateString(),
                    'time' => $time,
                    'state' => match ($job->status) {
                        'completed' => 'completed',
                        'delayed' => 'attention',
                        'in-progress' => 'in-progress',
                        default => 'scheduled',
                    },
                ];
            }
        }

        $load = [];
        foreach ($blocks as $block) {
            $load[$block['crewId'].'|'.$block['date']] = ($load[$block['crewId'].'|'.$block['date']] ?? 0) + 1;
        }

        return array_map(function (array $block) use ($load) {
            if ($block['state'] !== 'completed' && $load[$block['crewId'].'|'.$block['date']] > 1) {
                $block['state'] = 'conflict';
            }

            return $block;
        }, $blocks);
    }

    /**
     * The crew a job sits on: its team, or failing that the team of whoever is
     * running its tasks. Zero when neither says — drawn on a "No crew" row
     * rather than dropped.
     */
    private function crewId(Job $job): int
    {
        return $job->team_id
            ?? $job->tasks->map(fn ($task) => $task->foreman?->team_id)->filter()->first()
            ?? 0;
    }

    /**
     * One row per team, plus "No crew" when a drawn job has none.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function crews(array $blocks): array
    {
        $crews = Team::query()
            ->orderBy('name')
            ->withCount('members')
            ->get()
            ->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'memberCount' => $team->members_count,
            ])
            ->all();

        if (in_array(0, array_column($blocks, 'crewId'), true)) {
            $crews[] = ['id' => 0, 'name' => 'No crew', 'memberCount' => 0];
        }

        return $crews;
    }

    /** "8:00 AM – 4:30 PM" from the job's own working hours, or null when it has none. */
    private function timeRange(Job $job): ?string
    {
        $schedule = $job->schedule;

        if ($schedule?->work_start_time === null || $schedule->work_end_time === null) {
            return null;
        }

        return Carbon::parse($schedule->work_start_time)->format('g:i A')
            .' – '.Carbon::parse($schedule->work_end_time)->format('g:i A');
    }

    /** What the job's tasks add up to, or null when nobody has put hours against it. */
    private function hours(Job $job): ?float
    {
        $estimated = $job->tasks->whereNotNull('estimated_hours');

        return $estimated->isEmpty() ? null : round((float) $estimated->sum('estimated_hours'), 1);
    }
}
