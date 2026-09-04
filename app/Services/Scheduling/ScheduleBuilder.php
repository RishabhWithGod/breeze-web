<?php

namespace App\Services\Scheduling;

use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\JobTaskDependency;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raises a working schedule for a job that has none.
 *
 * A job that arrives from a reviewed takeoff already says what the work is and
 * roughly how long it takes, so it should not land on an empty schedule screen.
 * This builds the standard electrical sequence — survey through handover — sized
 * from the job's own estimated hours, wired up in order, with milestones on the
 * dates that matter and the crew already named.
 *
 * Idempotent: a job that already has a schedule is returned untouched, so calling
 * this from a listener cannot double-plan a job.
 */
class ScheduleBuilder
{
    /**
     * The default sequence, as shares of the job's estimated hours.
     *
     * Weights rather than fixed durations, because the same sequence covers a
     * two-day board change and a three-month fit-out. They sum to 1.
     *
     * `role` is who leads that stage; `milestone` marks the stages whose completion
     * is a date the client is told about.
     *
     * @var list<array{key: string, title: string, category: string, weight: float, role: string, priority: string, milestone: bool, description: string}>
     */
    private const SEQUENCE = [
        [
            'key' => 'survey',
            'title' => 'Site survey and verification',
            'category' => 'survey',
            'weight' => 0.08,
            'role' => 'estimator',
            'priority' => 'high',
            'milestone' => false,
            'description' => 'Walk the site, verify the drawing against what is actually there, and confirm access.',
        ],
        [
            'key' => 'permits',
            'title' => 'Permits and approvals',
            'category' => 'survey',
            'weight' => 0.05,
            'role' => 'project-manager',
            'priority' => 'critical',
            'milestone' => true,
            'description' => 'Lodge the electrical permit and hold until the authority approves.',
        ],
        [
            'key' => 'materials',
            'title' => 'Material procurement',
            'category' => 'survey',
            'weight' => 0.07,
            'role' => 'project-manager',
            'priority' => 'high',
            'milestone' => false,
            'description' => 'Order against the bill of quantities and confirm lead times on long-lead items.',
        ],
        [
            'key' => 'rough-in',
            'title' => 'Rough-in and containment',
            'category' => 'rough-in',
            'weight' => 0.25,
            'role' => 'foreman',
            'priority' => 'high',
            'milestone' => false,
            'description' => 'Conduit, trays and back boxes in place ready for cable.',
        ],
        [
            'key' => 'cabling',
            'title' => 'Cable pulling and installation',
            'category' => 'installation',
            'weight' => 0.22,
            'role' => 'electrician',
            'priority' => 'high',
            'milestone' => false,
            'description' => 'Pull and dress all circuits to the panel schedule.',
        ],
        [
            'key' => 'terminations',
            'title' => 'Terminations and devices',
            'category' => 'termination',
            'weight' => 0.15,
            'role' => 'electrician',
            'priority' => 'medium',
            'milestone' => false,
            'description' => 'Terminate at both ends, fit devices and label every circuit.',
        ],
        [
            'key' => 'testing',
            'title' => 'Testing and certification',
            'category' => 'testing',
            'weight' => 0.08,
            'role' => 'technician',
            'priority' => 'high',
            'milestone' => false,
            'description' => 'Insulation resistance, continuity, RCD and polarity, recorded on the certificate.',
        ],
        [
            'key' => 'inspection',
            'title' => 'Final inspection',
            'category' => 'inspection',
            'weight' => 0.06,
            'role' => 'inspector',
            'priority' => 'critical',
            'milestone' => true,
            'description' => 'Authority inspection and sign-off against the approved design.',
        ],
        [
            'key' => 'handover',
            'title' => 'Client handover',
            'category' => 'handover',
            'weight' => 0.04,
            'role' => 'project-manager',
            'priority' => 'medium',
            'milestone' => true,
            'description' => 'Hand over certificates, as-builts and the operating manual.',
        ],
    ];

    /**
     * Dependencies between the stages, by key.
     *
     * Mostly finish-to-start — you cannot pull cable through conduit that is not
     * there. Terminations run start-to-start against cabling because on any real job
     * the sparks begin terminating the first floor while the second is still being
     * pulled.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const EDGES = [
        ['permits', 'survey', JobTaskDependency::FINISH_TO_START],
        ['materials', 'survey', JobTaskDependency::FINISH_TO_START],
        ['rough-in', 'permits', JobTaskDependency::FINISH_TO_START],
        ['rough-in', 'materials', JobTaskDependency::FINISH_TO_START],
        ['cabling', 'rough-in', JobTaskDependency::FINISH_TO_START],
        ['terminations', 'cabling', JobTaskDependency::START_TO_START],
        ['testing', 'terminations', JobTaskDependency::FINISH_TO_START],
        ['inspection', 'testing', JobTaskDependency::FINISH_TO_START],
        ['handover', 'inspection', JobTaskDependency::FINISH_TO_START],
    ];

    public function __construct(private readonly ScheduleProgress $progress) {}

    /**
     * Builds the schedule, or returns the one already there.
     *
     * @param  bool  $withTasks  False raises the envelope only, for a job being planned by hand.
     */
    public function build(Job $job, ?User $author = null, bool $withTasks = true): JobSchedule
    {
        $existing = $job->schedule;

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($job, $author, $withTasks) {
            $starts = $job->start_date ? $job->start_date->copy() : Carbon::today();

            $schedule = JobSchedule::create([
                'job_id' => $job->id,
                'created_by' => $author?->id,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $job->end_date?->toDateString(),
                'working_days' => JobSchedule::DEFAULT_WORKING_DAYS,
                'work_start_time' => '08:00:00',
                'work_end_time' => '16:30:00',
                'break_minutes' => 30,
                'timezone' => config('app.timezone', 'UTC'),
                'holidays' => [],
                'status' => JobSchedule::STATUS_DRAFT,
            ]);

            if ($withTasks) {
                $this->addSequence($schedule, $job, $author);
            }

            // The window follows the work when the job did not carry an end date.
            $this->realignWindow($schedule);
            $this->progress->refresh($schedule);

            $job->recordActivity(
                'schedule_created',
                $withTasks
                    ? 'Schedule raised with '.count(self::SEQUENCE).' default tasks'
                    : 'Schedule raised',
                ['schedule_id' => $schedule->id],
            );

            return $schedule->refresh();
        });
    }

    /**
     * Writes the standard sequence, sized from the job's estimated hours.
     *
     * Dates are laid end to end on the schedule's own working calendar, so a task
     * never lands on a Sunday and the durations mean working days throughout.
     */
    private function addSequence(JobSchedule $schedule, Job $job, ?User $author): void
    {
        $totalHours = max(8.0, (float) ($job->estimated_hours ?? 40));
        $hoursPerDay = max(1.0, $schedule->hoursPerDay());
        $crew = $this->crewByRole();

        $cursor = $schedule->nextWorkingDay($schedule->starts_on ?? Carbon::today());
        $created = [];

        foreach (self::SEQUENCE as $position => $stage) {
            $hours = round($totalHours * $stage['weight'], 2);
            // A milestone is a date, not a span, so it never consumes days.
            $days = $stage['milestone'] ? 0 : max(1, (int) ceil($hours / $hoursPerDay));

            $startsOn = $cursor->copy();
            $endsOn = $days <= 1
                ? $startsOn->copy()
                : $schedule->addWorkingDays($startsOn, $days - 1);

            $task = JobTask::create([
                'job_schedule_id' => $schedule->id,
                'job_id' => $job->id,
                'created_by' => $author?->id,
                'title' => $stage['title'],
                'description' => $stage['description'],
                'status' => JobTask::STATUS_PENDING,
                'priority' => $stage['priority'],
                'category' => $stage['category'],
                'estimated_hours' => $hours,
                'actual_hours' => 0,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                // Baselined at creation, so any later move is measurable as slippage.
                'baseline_ends_on' => $endsOn->toDateString(),
                'completion_pct' => 0,
                'position' => $position,
                'is_milestone' => $stage['milestone'],
            ]);

            $created[$stage['key']] = $task;

            $member = $crew[$stage['role']] ?? null;

            if ($member !== null) {
                $task->assignments()->create([
                    'team_member_id' => $member->id,
                    'assigned_by' => $author?->id,
                    'role' => $stage['role'],
                ]);
            }

            // The next stage begins the working day after this one ends.
            $cursor = $schedule->addWorkingDays($endsOn, 1);
        }

        foreach (self::EDGES as [$successor, $predecessor, $type]) {
            if (! isset($created[$successor], $created[$predecessor])) {
                continue;
            }

            JobTaskDependency::create([
                'job_task_id' => $created[$successor]->id,
                'depends_on_id' => $created[$predecessor]->id,
                'type' => $type,
                'lag_days' => 0,
            ]);
        }

        // Whatever has nothing left to wait for can be worked on now.
        $this->markReady($schedule);
    }

    /** Promotes `pending` tasks with satisfied predecessors to `ready`. */
    public function markReady(JobSchedule $schedule): int
    {
        $ready = TaskDependencyGraph::for($schedule)->readyToStart();

        if ($ready === []) {
            return 0;
        }

        return JobTask::whereIn('id', $ready)->update(['status' => JobTask::STATUS_READY]);
    }

    /**
     * Pulls the schedule's window around the tasks it actually holds.
     *
     * A job that arrived without an end date gets one from the work; a job that had
     * one keeps it, because that date was a commitment rather than a calculation.
     */
    public function realignWindow(JobSchedule $schedule): void
    {
        // `tasks()` orders by position and id for the schedule view. That
        // ordering is meaningless once collapsed to a single aggregate row,
        // and MySQL rejects the query outright for selecting an ordering
        // column it cannot aggregate — so it is dropped first.
        $bounds = $schedule->tasks()
            ->reorder()
            ->selectRaw('min(starts_on) as first_day, max(ends_on) as last_day')
            ->first();

        if ($bounds?->first_day === null) {
            return;
        }

        $changes = [];

        if ($schedule->starts_on === null) {
            $changes['starts_on'] = $bounds->first_day;
        }

        if ($schedule->ends_on === null && $bounds->last_day !== null) {
            $changes['ends_on'] = $bounds->last_day;
        }

        if ($changes !== []) {
            $schedule->forceFill($changes)->saveQuietly();
        }
    }

    /**
     * One crew member per role, picked from the team's actual job titles.
     *
     * @return array<string, TeamMember>
     */
    private function crewByRole(): array
    {
        $members = TeamMember::query()->orderBy('id')->get();

        if ($members->isEmpty()) {
            return [];
        }

        // Role names on the task are ours; team members carry real job titles, so
        // each is matched on the words that appear in practice.
        $wanted = [
            'estimator' => ['Estimator'],
            'project-manager' => ['Site Supervisor', 'Project Manager'],
            'foreman' => ['Site Supervisor', 'Master Electrician'],
            'electrician' => ['Journeyman Electrician', 'Master Electrician'],
            'technician' => ['Controls Technician', 'Apprentice'],
            'inspector' => ['Master Electrician', 'Site Supervisor'],
            'reviewer' => ['Estimator', 'Project Manager'],
        ];

        $resolved = [];

        foreach ($wanted as $role => $titles) {
            foreach ($titles as $title) {
                $match = $members->firstWhere('role', $title);

                if ($match !== null) {
                    $resolved[$role] = $match;
                    break;
                }
            }

            // Rather than leave a stage unowned, fall back to whoever is on the books.
            $resolved[$role] ??= $members->first();
        }

        return $resolved;
    }
}
