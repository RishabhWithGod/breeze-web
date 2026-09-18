<?php

namespace App\Http\Controllers;

use App\Events\ScheduleChanged;
use App\Http\Resources\CrewShiftResource;
use App\Http\Resources\JobTaskResource;
use App\Models\CrewShift;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\JobTaskDependency;
use App\Models\TeamMember;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\ScheduleBuilder;
use App\Services\Scheduling\ScheduleProgress;
use App\Services\Scheduling\TaskDependencyGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A job's schedule: the plan, and everything read off it.
 *
 * The screen is one request. Every panel — status, timeline, calendar, crew,
 * progress, upcoming, delays, dependencies, resources — is a different reading of
 * the same task set, so they are computed together from one load rather than each
 * fetching its own.
 *
 * A job without a schedule gets one on first view. That is the automation
 * requirement: a job raised from a reviewed takeoff should never land on an empty
 * screen, and `ScheduleBuilder` is idempotent so this cannot double-plan.
 */
class JobScheduleController extends Controller
{
    /** How far ahead the upcoming-tasks panel looks. */
    private const UPCOMING_DAYS = 14;

    public function __construct(
        private readonly ScheduleBuilder $builder,
        private readonly ScheduleProgress $progress,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function show(Request $request, Job $job): Response
    {
        $this->authorize('view', $job);

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user());

        $tasks = $schedule->tasks()
            ->with(['assignments.member', 'dependencies.dependsOn:id,title'])
            ->withCount(['comments', 'attachments'])
            ->get();

        $graph = TaskDependencyGraph::for($schedule);
        $today = Carbon::today();

        return Inertia::render('JobSchedule', [
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'status' => $job->status,
                'isLocked' => $job->isLocked(),
                'priority' => $job->priority ?? 'medium',
                'jobType' => $job->job_type,
                'budget' => $job->budget === null ? null : (float) $job->budget,
                'foreman' => $job->foreman ? [
                    'name' => $job->foreman->name,
                    'initials' => $job->foreman->initials,
                ] : null,
            ],
            'schedule' => $this->schedulePayload($schedule),
            'tasks' => JobTaskResource::collection($tasks)->resolve(),
            'progress' => $this->progress->for($schedule, $tasks),
            'timeline' => $this->timeline($schedule, $tasks),
            'calendar' => $this->calendar($schedule, $tasks, $today),
            'crewShifts' => CrewShiftResource::collection(
                CrewShift::query()
                    ->with(['teamMember:id,name,initials,role', 'job:id,name,client,job_type,priority'])
                    ->where('job_id', $job->id)
                    ->orderBy('scheduled_date')
                    ->orderBy('start_time')
                    ->get()
            )->resolve(),
            'upcoming' => $this->upcoming($tasks, $today),
            'delays' => $this->delays($tasks, $graph),
            'dependencies' => $this->dependencies($tasks, $graph),
            'resources' => $this->resources($schedule, $tasks),
            'milestones' => $this->milestones($tasks),
            'criticalPath' => $graph->criticalPath(),
            'activity' => $this->activity($job),
            'members' => TeamMember::query()->orderBy('name')->get(['id', 'name', 'initials', 'role'])
                ->map(fn (TeamMember $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'initials' => $member->initials,
                    'role' => $member->role,
                ])->all(),
            'options' => [
                'statuses' => JobTask::STATUSES,
                'priorities' => JobTask::PRIORITIES,
                'categories' => JobTask::CATEGORIES,
                'roles' => JobTask::ROLES,
                'dependencyTypes' => JobTaskDependency::TYPES,
                'scheduleStatuses' => JobSchedule::STATUSES,
            ],
            'can' => $this->policy->abilities($request->user(), $schedule),
            'today' => $today->toDateString(),
        ]);
    }

    /**
     * Changes the plan: window, working week, holidays, status.
     *
     * The window is validated as a pair — an end before a start is not a schedule —
     * and the check is `after_or_equal` rather than `after` so a one-day job is legal.
     */
    public function update(Request $request, Job $job): RedirectResponse
    {
        $this->authorize('view', $job);
        $job->assertNotLocked();

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user(), withTasks: false);
        $this->authorizeAbility($request, 'update', $schedule);

        $data = $request->validate([
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'work_start_time' => ['required', 'date_format:H:i'],
            'work_end_time' => ['required', 'date_format:H:i', 'after:work_start_time'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'timezone' => ['required', 'string', 'timezone'],
            'holidays' => ['nullable', 'array'],
            'holidays.*' => ['date'],
            'status' => ['required', Rule::in(JobSchedule::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'work_end_time.after' => 'The working day has to end after it starts.',
        ]);

        $before = [
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
        ];

        $schedule->fill([
            ...$data,
            'working_days' => array_values(array_unique(array_map('intval', $data['working_days']))),
            'holidays' => array_values(array_unique(array_map(
                fn ($date) => Carbon::parse($date)->toDateString(),
                $data['holidays'] ?? [],
            ))),
        ]);

        // Publishing is a one-way stamp: it is what tells the crew the plan is real.
        if ($schedule->status !== JobSchedule::STATUS_DRAFT && $schedule->published_at === null) {
            $schedule->published_at = now();
        }

        $schedule->save();
        $this->progress->refresh($schedule);

        $after = [
            'starts_on' => $schedule->starts_on?->toDateString(),
            'ends_on' => $schedule->ends_on?->toDateString(),
        ];

        $description = $before === $after
            ? 'Schedule settings updated'
            : "Schedule window moved to {$after['starts_on']} – {$after['ends_on']}";

        $job->recordActivity('schedule_updated', $description, ['before' => $before, 'after' => $after]);

        event(new ScheduleChanged($job->id, 'schedule_updated', $description));

        return back()->with('success', 'The schedule was updated.');
    }

    /* ------------------------------------------------------------- panels */

    /** @return array<string, mixed> */
    private function schedulePayload(JobSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'startsOn' => $schedule->starts_on?->toDateString(),
            'endsOn' => $schedule->ends_on?->toDateString(),
            'workingDays' => $schedule->workingDays(),
            'workStartTime' => substr((string) $schedule->work_start_time, 0, 5),
            'workEndTime' => substr((string) $schedule->work_end_time, 0, 5),
            'breakMinutes' => $schedule->break_minutes,
            'hoursPerDay' => $schedule->hoursPerDay(),
            'timezone' => $schedule->timezone,
            'holidays' => $schedule->holidayDates(),
            'status' => $schedule->status,
            'progressPct' => $schedule->progress_pct,
            'notes' => $schedule->notes,
            'publishedAt' => $schedule->published_at?->toISOString(),
            'durationWorkingDays' => $schedule->durationInWorkingDays(),
        ];
    }

    /**
     * The timeline: each task as a bar positioned across the schedule's window.
     *
     * Offsets are percentages of the window rather than pixels, so the bars stay
     * correct at any width and the client does no date arithmetic.
     *
     * @return array<string, mixed>
     */
    private function timeline(JobSchedule $schedule, Collection $tasks): array
    {
        $dated = $tasks->filter(fn (JobTask $task) => $task->starts_on && $task->ends_on);

        $from = $schedule->starts_on ?? ($dated->min('starts_on') ? Carbon::parse($dated->min('starts_on')) : null);
        $to = $schedule->ends_on ?? ($dated->max('ends_on') ? Carbon::parse($dated->max('ends_on')) : null);

        if ($from === null || $to === null || $to->lt($from)) {
            return ['from' => null, 'to' => null, 'totalDays' => 0, 'bars' => [], 'months' => []];
        }

        $totalDays = max(1, (int) $from->diffInDays($to) + 1);

        $bars = $dated->map(function (JobTask $task) use ($from, $totalDays) {
            $offset = max(0, (int) $from->diffInDays($task->starts_on));
            $span = max(1, (int) $task->starts_on->diffInDays($task->ends_on) + 1);

            return [
                'taskId' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'category' => $task->category,
                'isMilestone' => $task->is_milestone,
                'completionPct' => $task->completion_pct,
                'isOverdue' => $task->isOverdue(),
                // Clamped so a task running past the window still draws inside it.
                'offsetPct' => round(min(100, $offset / $totalDays * 100), 3),
                'widthPct' => round(min(100 - min(100, $offset / $totalDays * 100), $span / $totalDays * 100), 3),
                'startsOn' => $task->starts_on->toDateString(),
                'endsOn' => $task->ends_on->toDateString(),
            ];
        })->values()->all();

        // Month ticks, so the axis reads without a label per day.
        $months = [];

        for ($cursor = $from->copy()->startOfMonth(); $cursor->lte($to); $cursor->addMonth()) {
            $monthStart = $cursor->lt($from) ? $from->copy() : $cursor->copy();
            $offset = (int) $from->diffInDays($monthStart);

            $months[] = [
                'label' => $cursor->format('M Y'),
                'offsetPct' => round($offset / $totalDays * 100, 3),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totalDays' => $totalDays,
            'bars' => $bars,
            'months' => $months,
            'todayOffsetPct' => Carbon::today()->between($from, $to)
                ? round((int) $from->diffInDays(Carbon::today()) / $totalDays * 100, 3)
                : null,
        ];
    }

    /**
     * A month grid for the schedule's own window, with what falls on each day.
     *
     * Tasks are spread across every day they span, so a five-day task appears on all
     * five rather than only on its start.
     *
     * @return array<string, mixed>
     */
    private function calendar(JobSchedule $schedule, Collection $tasks, Carbon $today): array
    {
        $anchor = $schedule->starts_on && $today->lt($schedule->starts_on)
            ? $schedule->starts_on->copy()
            : $today->copy();

        $from = $anchor->copy()->startOfMonth()->startOfWeek(Carbon::SUNDAY);
        $to = $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $byDate = [];

        foreach ($tasks as $task) {
            if ($task->starts_on === null) {
                continue;
            }

            $end = $task->ends_on ?? $task->starts_on;

            for ($day = $task->starts_on->copy(); $day->lte($end); $day->addDay()) {
                $key = $day->toDateString();

                if ($day->lt($from) || $day->gt($to)) {
                    continue;
                }

                $byDate[$key][] = [
                    'taskId' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'category' => $task->category,
                    'isMilestone' => $task->is_milestone,
                    // Only the first and last day carry the marker, so a long task
                    // reads as a span rather than a repeated block.
                    'isStart' => $day->isSameDay($task->starts_on),
                    'isEnd' => $day->isSameDay($end),
                ];
            }
        }

        $holidays = $schedule->holidayDates();
        $days = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();

            $days[] = [
                'date' => $key,
                'dayOfMonth' => $day->day,
                'weekday' => $day->format('D'),
                'label' => $day->format('D, m/d'),
                'isToday' => $day->isSameDay($today),
                'isCurrentPeriod' => $day->isSameMonth($anchor),
                'isWorkingDay' => $schedule->isWorkingDay($day),
                'isHoliday' => in_array($key, $holidays, true),
                'items' => $byDate[$key] ?? [],
            ];
        }

        return [
            'monthLabel' => $anchor->format('F Y'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * What is due next, and what is ready to start.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(Collection $tasks, Carbon $today): array
    {
        $horizon = $today->copy()->addDays(self::UPCOMING_DAYS);

        return $tasks
            ->reject(fn (JobTask $task) => $task->isClosed())
            ->filter(fn (JobTask $task) => $task->ends_on !== null
                && $task->ends_on->gte($today)
                && $task->ends_on->lte($horizon))
            ->sortBy('ends_on')
            ->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'endsOn' => $task->ends_on->toDateString(),
                'dueLabel' => $task->ends_on->format('m/d/Y'),
                'daysAway' => (int) $today->diffInDays($task->ends_on),
                'isMilestone' => $task->is_milestone,
                'assignees' => $task->assignments
                    ->map(fn ($assignment) => $assignment->member?->name)
                    ->filter()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything running late, with why.
     *
     * A task is late either because somebody recorded a delay or because its end date
     * has passed and it is still open — the second is the one that catches work
     * nobody has looked at.
     *
     * @return list<array<string, mixed>>
     */
    private function delays(Collection $tasks, TaskDependencyGraph $graph): array
    {
        $blocked = $graph->blocked();

        return $tasks
            ->filter(fn (JobTask $task) => $task->status === JobTask::STATUS_DELAYED || $task->isOverdue())
            ->sortByDesc(fn (JobTask $task) => $task->daysLate())
            ->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'endsOn' => $task->ends_on?->toDateString(),
                'daysLate' => $task->daysLate(),
                'slippedDays' => $task->slippedDays(),
                'completionPct' => $task->completion_pct,
                'notes' => $task->notes,
                /* Named blockers turn "late" into something actionable. */
                'blockedBy' => $blocked[$task->id] ?? [],
                'assignees' => $task->assignments
                    ->map(fn ($assignment) => $assignment->member?->name)
                    ->filter()->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The dependency graph as the panel shows it, plus anything currently broken.
     *
     * @return array<string, mixed>
     */
    private function dependencies(Collection $tasks, TaskDependencyGraph $graph): array
    {
        $edges = [];

        foreach ($tasks as $task) {
            foreach ($task->dependencies as $edge) {
                $edges[] = [
                    'id' => $edge->id,
                    'taskId' => $task->id,
                    'taskTitle' => $task->title,
                    'taskStatus' => $task->status,
                    'dependsOnId' => $edge->depends_on_id,
                    'dependsOnTitle' => $edge->dependsOn?->title ?? 'Unknown task',
                    'type' => $edge->type,
                    'typeLabel' => $edge->label(),
                    'lagDays' => $edge->lag_days,
                ];
            }
        }

        return [
            'edges' => $edges,
            'breaches' => $graph->breaches(),
            'blocked' => $graph->blocked(),
            'readyToStart' => $graph->readyToStart(),
        ];
    }

    /**
     * Who is on this job, what they are carrying, and where they clash.
     *
     * Workload is task hours on this job — not the crew-availability screen's figure,
     * which counts booked shifts across every job. The two answer different questions
     * and are deliberately not the same number.
     *
     * @return list<array<string, mixed>>
     */
    private function resources(JobSchedule $schedule, Collection $tasks): array
    {
        $byMember = [];

        foreach ($tasks as $task) {
            foreach ($task->assignments as $assignment) {
                $member = $assignment->member;

                if ($member === null) {
                    continue;
                }

                $byMember[$member->id] ??= [
                    'id' => $member->id,
                    'name' => $member->name,
                    'initials' => $member->initials,
                    'title' => $member->role,
                    'roles' => [],
                    'taskCount' => 0,
                    'openCount' => 0,
                    'hours' => 0.0,
                    'lateCount' => 0,
                ];

                $byMember[$member->id]['roles'][] = $assignment->role;
                $byMember[$member->id]['taskCount']++;
                $byMember[$member->id]['hours'] += (float) ($task->estimated_hours ?? 0);

                if (! $task->isClosed()) {
                    $byMember[$member->id]['openCount']++;
                }

                if ($task->status === JobTask::STATUS_DELAYED || $task->isOverdue()) {
                    $byMember[$member->id]['lateCount']++;
                }
            }
        }

        // Crew shifts already booked elsewhere in the window, so a resource that is
        // physically unavailable is visible here rather than only on the crew screen.
        $conflicts = [];

        if ($schedule->starts_on && $schedule->ends_on) {
            $conflicts = CrewShift::query()
                // Other jobs the same manager runs — not this one, and never
                // another manager's, which this schedule has no business
                // reading conflicts against.
                ->whereHas('job', fn ($query) => $query->where('user_id', $schedule->job->user_id))
                ->whereBetween('scheduled_date', [
                    $schedule->starts_on->toDateString(),
                    $schedule->ends_on->toDateString(),
                ])
                ->where('job_id', '!=', $schedule->job_id)
                ->whereIn('team_member_id', array_keys($byMember))
                ->selectRaw('team_member_id, count(*) as shifts, sum(duration_hours) as hours')
                ->groupBy('team_member_id')
                ->get()
                ->keyBy('team_member_id');
        }

        return collect($byMember)
            ->map(function (array $row) use ($conflicts) {
                $elsewhere = $conflicts[$row['id']] ?? null;

                return [
                    ...$row,
                    'roles' => array_values(array_unique($row['roles'])),
                    'hours' => round($row['hours'], 2),
                    'shiftsElsewhere' => (int) ($elsewhere->shifts ?? 0),
                    'hoursElsewhere' => round((float) ($elsewhere->hours ?? 0), 2),
                ];
            })
            ->sortByDesc('hours')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function milestones(Collection $tasks): array
    {
        return $tasks
            ->where('is_milestone', true)
            ->sortBy(fn (JobTask $task) => $task->ends_on?->toDateString() ?? '9999-12-31')
            ->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'endsOn' => $task->ends_on?->toDateString(),
                'dueLabel' => $task->ends_on?->format('m/d/Y'),
                'isMet' => $task->isComplete(),
                'isOverdue' => $task->isOverdue(),
                'daysLate' => $task->daysLate(),
            ])
            ->values()
            ->all();
    }

    /** Scheduling entries from the job's own activity trail. */
    private function activity(Job $job, int $limit = 12): array
    {
        return $job->activities()
            ->whereIn('type', [
                'schedule_created', 'schedule_updated',
                'task_created', 'task_updated', 'task_assigned', 'task_unassigned',
                'task_completed', 'task_delayed', 'task_deleted', 'task_reordered',
                'task_moved', 'dependency_added', 'dependency_removed',
            ])
            ->with('actor:id,name')
            ->take($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'type' => $row->type,
                'description' => $row->description,
                'at' => $row->created_at?->toISOString(),
                'author' => $row->actor?->name,
            ])
            ->all();
    }

    /**
     * Refuses an action this user's role does not carry.
     *
     * A 403 rather than a validation error: the screen is handed `can` and hides
     * what the role cannot do, so arriving here means the request was forged.
     */
    private function authorizeAbility(Request $request, string $ability, JobSchedule $schedule): void
    {
        abort_unless($this->policy->{$ability}($request->user(), $schedule), 403);
    }
}
