<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\JobTaskDependency;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use App\Services\Scheduling\TaskDependencyGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A job's schedule: the plan, its tasks, and the graph that orders them.
 *
 * The rules worth pinning are the ones a schedule is wrong without: dates that
 * contradict themselves, a dependency graph that can never start, progress that
 * disagrees with the tasks, and a role doing something it should not.
 */
class JobScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    private TeamMember $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
        $this->lead = TeamMember::create([
            'name' => 'Michael Torres',
            'initials' => 'MT',
            'role' => 'Master Electrician',
        ]);
    }

    /* ------------------------------------------------------------- automation */

    /**
     * A job should never land on an empty schedule.
     *
     * The work is already known from the takeoff, so the sequence, its dates, the
     * milestones and the crew are all raised on first view.
     */
    public function test_a_job_without_a_schedule_gets_one_on_first_view(): void
    {
        $job = $this->makeJob(['estimated_hours' => 80]);

        $this->assertNull($job->schedule);

        $this->actingAs($this->planner)
            ->get("/jobs/{$job->id}/schedule")
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobSchedule')
                ->has('tasks', 9)
                ->has('milestones', 3)
                // The sequence is wired up, not a flat list.
                ->has('dependencies.edges', 9)
                ->where('dependencies.breaches', [])
                ->where('schedule.status', JobSchedule::STATUS_DRAFT));

        // Idempotent: viewing again must not plan the job twice.
        $this->actingAs($this->planner)->get("/jobs/{$job->id}/schedule")->assertOk();

        $this->assertSame(1, JobSchedule::where('job_id', $job->id)->count());
        $this->assertSame(9, JobTask::where('job_id', $job->id)->count());
    }

    /** Default tasks are sized from the job's hours and laid on working days only. */
    public function test_default_tasks_are_sized_and_land_on_working_days(): void
    {
        $job = $this->makeJob(['estimated_hours' => 80, 'start_date' => '2026-10-05']);
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner);
        $tasks = $schedule->tasks()->get();

        // The weights sum to the job's estimate.
        $this->assertEqualsWithDelta(80.0, (float) $tasks->sum('estimated_hours'), 0.5);

        foreach ($tasks as $task) {
            $this->assertTrue(
                $schedule->isWorkingDay($task->starts_on),
                "{$task->title} starts on a non-working day ({$task->starts_on->format('D')})",
            );
        }

        // Baselined at creation, so a later move is measurable.
        $this->assertNotNull($tasks->first()->baseline_ends_on);
    }

    /* ------------------------------------------------------------- validation */

    public function test_a_schedule_cannot_end_before_it_starts(): void
    {
        $job = $this->makeJob();
        app(ScheduleBuilder::class)->build($job, $this->planner);

        $this->actingAs($this->planner)
            ->put("/jobs/{$job->id}/schedule", $this->schedulePayload([
                'starts_on' => '2026-10-20',
                'ends_on' => '2026-10-10',
            ]))
            ->assertSessionHasErrors('ends_on');
    }

    public function test_a_task_cannot_end_before_it_starts(): void
    {
        $job = $this->makeJob();
        app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $this->actingAs($this->planner)
            ->post("/jobs/{$job->id}/schedule/tasks", [
                'title' => 'Backwards task',
                'starts_on' => '2026-10-20',
                'ends_on' => '2026-10-10',
            ])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_a_schedule_cannot_hold_two_tasks_with_the_same_title(): void
    {
        $job = $this->makeJob();
        app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $this->actingAs($this->planner)
            ->post("/jobs/{$job->id}/schedule/tasks", ['title' => 'Pull cable'])
            ->assertSessionHasNoErrors();

        // Case and padding are not a difference the office cares about.
        $this->actingAs($this->planner)
            ->post("/jobs/{$job->id}/schedule/tasks", ['title' => '  pull CABLE  '])
            ->assertSessionHasErrors('title');

        $this->assertSame(1, JobTask::where('job_id', $job->id)->count());
    }

    /**
     * A circular dependency is refused outright.
     *
     * Broken dates are a warning — they can be corrected. A cycle cannot: a schedule
     * where A waits on B and B waits on A can never start at all.
     */
    public function test_a_circular_dependency_is_refused(): void
    {
        // Built without the default sequence, so the only edges are the ones this
        // test adds — the default tasks already wire themselves together.
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $first = $this->makeTask($schedule, ['title' => 'First']);
        $second = $this->makeTask($schedule, ['title' => 'Second']);
        $third = $this->makeTask($schedule, ['title' => 'Third']);

        // A chain: second waits on first, third waits on second.
        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$second->id}/dependencies", [
                'depends_on_id' => $first->id,
                'type' => JobTaskDependency::FINISH_TO_START,
            ])->assertSessionHasNoErrors();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$third->id}/dependencies", [
                'depends_on_id' => $second->id,
                'type' => JobTaskDependency::FINISH_TO_START,
            ])->assertSessionHasNoErrors();

        // Closing the loop: first waiting on third.
        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$first->id}/dependencies", [
                'depends_on_id' => $third->id,
                'type' => JobTaskDependency::FINISH_TO_START,
            ])
            ->assertSessionHasErrors('depends_on_id');

        $this->assertFalse(
            $first->dependencies()->where('depends_on_id', $third->id)->exists(),
            'The cycle-closing edge must not be written.',
        );
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        [, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$task->id}/dependencies", [
                'depends_on_id' => $task->id,
                'type' => JobTaskDependency::FINISH_TO_START,
            ])
            ->assertSessionHasErrors('depends_on_id');
    }

    /** A dependency across schedules would be a graph nobody can see. */
    public function test_a_dependency_must_stay_on_one_schedule(): void
    {
        [, $mine] = $this->planJob();
        [, $theirs] = $this->planJob(['name' => 'Another Job']);

        $task = $mine->tasks()->first();
        $foreign = $theirs->tasks()->first();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$task->id}/dependencies", [
                'depends_on_id' => $foreign->id,
                'type' => JobTaskDependency::FINISH_TO_START,
            ])
            ->assertSessionHasErrors('depends_on_id');
    }

    public function test_reordering_rejects_tasks_from_another_schedule(): void
    {
        [$job, $mine] = $this->planJob();
        [, $theirs] = $this->planJob(['name' => 'Another Job']);

        $order = [...$mine->tasks()->pluck('id')->all(), $theirs->tasks()->first()->id];

        $this->actingAs($this->planner)
            ->post("/jobs/{$job->id}/schedule/reorder", ['order' => $order])
            ->assertSessionHasErrors('order');
    }

    /* --------------------------------------------------------------- workflow */

    /** Completing a task promotes whatever it was blocking. */
    public function test_completing_a_task_unblocks_its_successors(): void
    {
        [, $schedule] = $this->planJob();

        $survey = $schedule->tasks()->where('title', 'like', 'Site survey%')->firstOrFail();
        $permits = $schedule->tasks()->where('title', 'like', 'Permits%')->firstOrFail();

        // Permits waits on the survey, so it starts out held.
        $this->assertSame(JobTask::STATUS_READY, $survey->status);
        $this->assertSame(JobTask::STATUS_PENDING, $permits->status);

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$survey->id}/complete", ['actual_hours' => 7])
            ->assertSessionHas('success');

        $survey->refresh();
        $this->assertSame(JobTask::STATUS_COMPLETED, $survey->status);
        $this->assertSame(100, $survey->completion_pct);
        $this->assertNotNull($survey->completed_at);
        $this->assertSame(JobTask::STATUS_READY, $permits->refresh()->status);
    }

    /** A delay records a reason and baselines the original date. */
    public function test_delaying_a_task_keeps_the_original_date_as_the_baseline(): void
    {
        [, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();
        $originalEnd = $task->ends_on->copy();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$task->id}/delay", [
                'ends_on' => $originalEnd->copy()->addDays(5)->toDateString(),
                'reason' => 'Switchgear delivery slipped a week.',
            ])
            ->assertSessionHas('warning');

        $task->refresh();

        $this->assertSame(JobTask::STATUS_DELAYED, $task->status);
        $this->assertSame($originalEnd->toDateString(), $task->baseline_ends_on->toDateString());
        $this->assertSame(5, $task->slippedDays());
        $this->assertStringContainsString('Switchgear', $task->notes);

        // Delaying again must not re-baseline to the already-slipped date.
        $this->actingAs($this->planner)->post("/schedule-tasks/{$task->id}/delay", [
            'ends_on' => $originalEnd->copy()->addDays(9)->toDateString(),
            'reason' => 'Still waiting.',
        ]);

        $this->assertSame($originalEnd->toDateString(), $task->refresh()->baseline_ends_on->toDateString());
        $this->assertSame(9, $task->slippedDays());
    }

    public function test_a_delay_needs_a_reason(): void
    {
        [, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$task->id}/delay", [
                'ends_on' => $task->ends_on->copy()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('reason');
    }

    /** Dragging a task keeps its duration rather than collapsing it to one day. */
    public function test_moving_a_task_preserves_its_span(): void
    {
        [, $schedule] = $this->planJob();
        $task = $schedule->tasks()->where('is_milestone', false)->orderByDesc('id')->first();

        // Give it a known five-day span.
        $task->forceFill(['starts_on' => '2026-10-05', 'ends_on' => '2026-10-09'])->save();

        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$task->id}/move", ['starts_on' => '2026-10-12'])
            ->assertSessionHasNoErrors();

        $task->refresh();

        $this->assertSame('2026-10-12', $task->starts_on->toDateString());
        $this->assertSame('2026-10-16', $task->ends_on->toDateString());
    }

    /**
     * A move that breaks a dependency warns rather than refuses.
     *
     * The dates may be the thing being corrected, and refusing the save would leave
     * the planner unable to fix either end.
     */
    public function test_a_move_that_breaks_a_dependency_warns_but_saves(): void
    {
        [, $schedule] = $this->planJob();

        $survey = $schedule->tasks()->where('title', 'like', 'Site survey%')->firstOrFail();
        $permits = $schedule->tasks()->where('title', 'like', 'Permits%')->firstOrFail();

        // Permits back before the survey finishes.
        $this->actingAs($this->planner)
            ->post("/schedule-tasks/{$permits->id}/move", [
                'starts_on' => $survey->starts_on->copy()->subDays(3)->toDateString(),
            ])
            ->assertSessionHas('warning');

        // Saved anyway.
        $this->assertTrue($permits->refresh()->starts_on->lt($survey->ends_on));

        $breaches = TaskDependencyGraph::for($schedule->refresh())->breaches();
        $this->assertNotEmpty($breaches);
        $this->assertStringContainsString('before', $breaches[0]['problem']);
    }

    public function test_tasks_can_be_reordered(): void
    {
        [$job, $schedule] = $this->planJob();
        $reversed = array_reverse($schedule->tasks()->pluck('id')->all());

        $this->actingAs($this->planner)
            ->post("/jobs/{$job->id}/schedule/reorder", ['order' => $reversed])
            ->assertSessionHas('success');

        $this->assertSame($reversed, $schedule->tasks()->pluck('id')->all());
    }

    /* --------------------------------------------------------------- progress */

    /**
     * Progress is weighted by hours, not counted per task.
     *
     * Ten two-hour tasks and one eighty-hour task are not the same amount of work,
     * and counting them equally would report a job as nearly done before the real
     * work started.
     */
    public function test_progress_is_weighted_by_estimated_hours(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $small = $this->makeTask($schedule, ['title' => 'Small', 'estimated_hours' => 2]);
        $this->makeTask($schedule, ['title' => 'Large', 'estimated_hours' => 78]);

        $this->actingAs($this->planner)->post("/schedule-tasks/{$small->id}/complete");

        $this->actingAs($this->planner)
            ->get("/jobs/{$job->id}/schedule")
            ->assertInertia(fn (Assert $page) => $page
                // One of two tasks done, but only 2 of 80 hours.
                ->where('progress.taskPct', 50)
                ->where('progress.workPct', 3)
                ->where('progress.completed', 1)
                ->where('progress.remaining', 1));
    }

    /** Cancelled work leaves the running total rather than counting as incomplete. */
    public function test_cancelled_tasks_are_excluded_from_progress(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $done = $this->makeTask($schedule, ['title' => 'Done', 'estimated_hours' => 10]);
        $this->makeTask($schedule, [
            'title' => 'Dropped from scope',
            'estimated_hours' => 90,
            'status' => JobTask::STATUS_CANCELLED,
        ]);

        $this->actingAs($this->planner)->post("/schedule-tasks/{$done->id}/complete");

        $this->actingAs($this->planner)
            ->get("/jobs/{$job->id}/schedule")
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.workPct', 100)
                ->where('progress.counted', 1)
                ->where('progress.cancelled', 1));
    }

    /** An open task whose end date has passed is late whether or not anyone said so. */
    public function test_an_overdue_task_counts_as_delayed_without_being_marked(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $this->makeTask($schedule, [
            'title' => 'Forgotten',
            'status' => JobTask::STATUS_IN_PROGRESS,
            'ends_on' => Carbon::today()->subDays(4)->toDateString(),
        ]);

        $this->actingAs($this->planner)
            ->get("/jobs/{$job->id}/schedule")
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress.delayed', 1)
                ->has('delays', 1)
                ->where('delays.0.daysLate', 4));
    }

    /* ------------------------------------------------------------- dependencies */

    /** Each dependency type is judged on its own terms, not all as "is it finished". */
    public function test_start_to_start_is_satisfied_once_the_predecessor_starts(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $first = $this->makeTask($schedule, ['title' => 'Pull cable']);
        $second = $this->makeTask($schedule, ['title' => 'Terminate']);

        $second->dependencies()->create([
            'depends_on_id' => $first->id,
            'type' => JobTaskDependency::START_TO_START,
        ]);

        $graph = TaskDependencyGraph::for($schedule);
        $this->assertFalse($graph->satisfied($second->id));

        // Started, not finished — enough for start-to-start.
        $first->forceFill(['status' => JobTask::STATUS_IN_PROGRESS])->save();

        $this->assertTrue(TaskDependencyGraph::for($schedule)->satisfied($second->id));
    }

    /** The critical path is the longest chain by duration, not by task count. */
    public function test_the_critical_path_follows_duration_not_task_count(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        $root = $this->makeTask($schedule, ['title' => 'Root', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-05']);
        // A long single-task branch.
        $long = $this->makeTask($schedule, ['title' => 'Long haul', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-25']);
        // A short two-task branch.
        $shortA = $this->makeTask($schedule, ['title' => 'Short A', 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-07']);
        $shortB = $this->makeTask($schedule, ['title' => 'Short B', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-09']);

        $long->dependencies()->create(['depends_on_id' => $root->id, 'type' => JobTaskDependency::FINISH_TO_START]);
        $shortA->dependencies()->create(['depends_on_id' => $root->id, 'type' => JobTaskDependency::FINISH_TO_START]);
        $shortB->dependencies()->create(['depends_on_id' => $shortA->id, 'type' => JobTaskDependency::FINISH_TO_START]);

        $path = TaskDependencyGraph::for($schedule)->criticalPath();

        $this->assertSame([$root->id, $long->id], $path);
    }

    /* ------------------------------------------------------------ permissions */

    /** An electrician can close their own work but cannot re-plan the job. */
    public function test_a_crew_member_may_complete_their_own_task_but_not_change_the_plan(): void
    {
        [$job, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();

        $sparks = User::factory()->create([
            'name' => $this->lead->name,
            'role' => 'Journeyman Electrician',
        ]);

        $task->assignments()->create(['team_member_id' => $this->lead->id, 'role' => 'electrician']);

        $this->actingAs($sparks)
            ->post("/schedule-tasks/{$task->id}/complete")
            ->assertSessionHas('success');

        $this->actingAs($sparks)
            ->put("/jobs/{$job->id}/schedule", $this->schedulePayload())
            ->assertForbidden();

        $this->actingAs($sparks)
            ->delete("/schedule-tasks/{$task->id}")
            ->assertForbidden();
    }

    /** Somebody not on the task cannot close it either. */
    public function test_a_crew_member_cannot_complete_a_task_they_are_not_on(): void
    {
        [, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();

        $stranger = User::factory()->create(['name' => 'Not On This Job', 'role' => 'Apprentice']);

        $this->actingAs($stranger)
            ->post("/schedule-tasks/{$task->id}/complete")
            ->assertForbidden();
    }

    public function test_the_screen_reports_what_the_role_may_do(): void
    {
        [$job] = $this->planJob();
        $apprentice = User::factory()->create(['role' => 'Apprentice']);

        $this->actingAs($apprentice)
            ->get("/jobs/{$job->id}/schedule")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.updateSchedule', false)
                ->where('can.deleteTask', false)
                // Everyone can read the plan and talk about it.
                ->where('can.comment', true));
    }

    /* ---------------------------------------------------------------- activity */

    public function test_every_change_leaves_an_activity_row(): void
    {
        [$job, $schedule] = $this->planJob();
        $task = $schedule->tasks()->first();

        $this->actingAs($this->planner)->post("/schedule-tasks/{$task->id}/assignments", [
            'team_member_id' => $this->lead->id,
            'role' => 'foreman',
        ]);
        $this->actingAs($this->planner)->post("/schedule-tasks/{$task->id}/complete");

        $types = $job->activities()->pluck('type')->all();

        $this->assertContains('schedule_created', $types);
        $this->assertContains('task_assigned', $types);
        $this->assertContains('task_completed', $types);
    }

    public function test_the_schedule_requires_signing_in(): void
    {
        $job = $this->makeJob();

        $this->get("/jobs/{$job->id}/schedule")->assertRedirect('/login');
    }

    /* --------------------------------------------------------------- helpers */

    /** @param  array<string, mixed>  $attributes */
    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'location' => '2580 Market Street, Philadelphia',
            'job_type' => 'commercial',
            'status' => 'in-progress',
            'priority' => 'high',
            'estimated_hours' => 40,
            'budget' => 87500,
            'start_date' => '2026-10-05',
            ...$attributes,
        ]);
    }

    /** @return array{0: Job, 1: JobSchedule} */
    private function planJob(array $attributes = []): array
    {
        $job = $this->makeJob($attributes);
        $schedule = app(ScheduleBuilder::class)->build($job, $this->planner);

        return [$job, $schedule->refresh()];
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeTask(JobSchedule $schedule, array $attributes = []): JobTask
    {
        return $schedule->tasks()->create([
            'job_id' => $schedule->job_id,
            'title' => 'Task '.uniqid(),
            'status' => JobTask::STATUS_READY,
            'priority' => 'medium',
            'position' => (int) $schedule->tasks()->max('position') + 1,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function schedulePayload(array $overrides = []): array
    {
        return [
            'starts_on' => '2026-10-05',
            'ends_on' => '2026-12-20',
            'working_days' => [1, 2, 3, 4, 5],
            'work_start_time' => '08:00',
            'work_end_time' => '16:30',
            'break_minutes' => 30,
            'timezone' => 'UTC',
            'holidays' => [],
            'status' => JobSchedule::STATUS_PUBLISHED,
            ...$overrides,
        ];
    }
}
