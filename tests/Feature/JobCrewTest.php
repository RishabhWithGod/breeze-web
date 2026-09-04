<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The crew a job is handed to, and who on it runs each task.
 *
 * Handing a job to a team is what narrows every later choice: the foreman
 * running a task and the supervisor over it are both picked from that crew
 * rather than from the whole register. A job with no crew is not narrowed —
 * otherwise work raised before teams existed could not be staffed at all.
 */
class JobCrewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Job $job;

    private Team $north;

    private Foreman $priya;

    private Foreman $torres;

    private Foreman $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->user->clients()->create(['name' => 'Harborview']);
        $project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);

        $this->north = Team::create(['name' => 'North Crew']);
        $this->priya = Foreman::create([
            'name' => 'Priya Raman', 'initials' => 'PR',
            'team_id' => $this->north->id, 'role' => 'foreman',
        ]);
        $this->torres = Foreman::create([
            'name' => 'Michael Torres', 'initials' => 'MT',
            'team_id' => $this->north->id, 'role' => 'supervisor',
        ]);
        // On another crew entirely — the person the narrowing exists to exclude.
        $south = Team::create(['name' => 'South Crew']);
        $this->stranger = Foreman::create([
            'name' => 'Luis Ortega', 'initials' => 'LO',
            'team_id' => $south->id, 'role' => 'foreman',
        ]);

        $this->job = Job::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'team_id' => $this->north->id,
            'name' => 'Riser rewire',
            'client' => 'Harborview',
            'status' => 'scheduled',
        ]);
    }

    /* --------------------------------------------- who the work can go to */

    public function test_the_task_step_offers_only_the_jobs_own_crew(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskSetup')
                ->where('team.name', 'North Crew')
                // Foremen and supervisors are separate lists: they answer
                // different questions on the same task.
                ->has('foremen', 1)
                ->where('foremen.0.name', 'Priya Raman')
                ->has('supervisors', 1)
                ->where('supervisors.0.name', 'Michael Torres'));
    }

    /**
     * A job with no crew is not narrowed.
     *
     * Work raised before teams existed belongs to nobody, and offering an empty
     * picker would leave it impossible to staff — a worse answer than a long
     * list.
     */
    public function test_a_job_with_no_crew_offers_the_whole_register(): void
    {
        $this->job->update(['team_id' => null]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->where('team', null)
                ->has('foremen', 3)
                ->has('supervisors', 1));
    }

    public function test_a_task_records_who_runs_it_and_who_is_over_it(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in',
                    'foreman_id' => $this->priya->id,
                    'supervisor_id' => $this->torres->id,
                    'estimate_item_ids' => [],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $task = JobTask::sole();

        $this->assertSame($this->priya->id, $task->foreman_id);
        $this->assertSame($this->torres->id, $task->supervisor_id);
    }

    /** A supervisor is optional: plenty of work has nobody above the foreman. */
    public function test_a_task_may_have_no_supervisor(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in',
                    'foreman_id' => $this->priya->id,
                    'estimate_item_ids' => [],
                ]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(JobTask::sole()->supervisor_id);
    }

    /**
     * The pickers only offer the crew, so this catches a hand-made request —
     * but the rule has to live on the server or the narrowing is decoration.
     */
    public function test_someone_off_the_crew_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in',
                    'foreman_id' => $this->stranger->id,
                    'estimate_item_ids' => [],
                ]],
            ])
            ->assertSessionHasErrors('foreman_id');

        $this->assertSame(0, JobTask::count());
    }

    public function test_a_supervisor_off_the_crew_is_refused_too(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in',
                    'foreman_id' => $this->priya->id,
                    'supervisor_id' => $this->stranger->id,
                    'estimate_item_ids' => [],
                ]],
            ])
            ->assertSessionHasErrors('foreman_id');

        $this->assertSame(0, JobTask::count());
    }

    public function test_correcting_a_task_keeps_the_crew_rule(): void
    {
        $schedule = JobSchedule::create([
            'job_id' => $this->job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);
        $task = $this->job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
            'status' => JobTask::STATUS_PENDING,
            'foreman_id' => $this->priya->id,
        ]);

        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', $task), [
                'title' => 'Rough-in',
                'status' => JobTask::STATUS_PENDING,
                'foreman_id' => $this->stranger->id,
                'estimate_item_ids' => [],
            ])
            ->assertSessionHasErrors('foreman_id');

        $this->assertSame($this->priya->id, $task->fresh()->foreman_id);
    }

    /* ------------------------------------------------ the crew, on screen */

    public function test_the_job_screens_name_the_crew(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.show', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->where('job.teamName', 'North Crew')
                ->where('job.teamId', $this->north->id));

        $this->actingAs($this->user)
            ->get(route('jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.teamName', 'North Crew'));
    }

    public function test_the_job_forms_offer_the_crews(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.create'))
            ->assertInertia(fn (Assert $page) => $page->has('teams', 2));

        $this->actingAs($this->user)
            ->get(route('jobs.edit', $this->job))
            ->assertInertia(fn (Assert $page) => $page->has('teams', 2));
    }

    /**
     * Disbanding a crew does not delete the work it was doing.
     *
     * The job simply stops naming one, and its pickers widen back out.
     */
    public function test_deleting_a_team_leaves_the_job_standing(): void
    {
        $this->north->delete();

        $this->assertNotNull($this->job->fresh());
        $this->assertNull($this->job->fresh()->team_id);
    }

    /* ------------------------------------------- recording one from the form */

    /**
     * A crew the register does not have yet, recorded without leaving the job.
     *
     * The register starts empty, so the very first job anyone raises needs this
     * — and sending them to Teams and back would lose the half-filled form.
     */
    public function test_a_team_can_be_added_from_inside_another_form(): void
    {
        $this->actingAs($this->user)
            ->from(route('jobs.create'))
            ->post(route('teams.store'), ['name' => 'West Crew', 'inline' => true])
            ->assertSessionHasNoErrors()
            // Back where they were, not off to the register with the form.
            ->assertRedirect(route('jobs.create'));

        $this->assertTrue(Team::where('name', 'West Crew')->exists());
    }

    /** The register's own form still lands on the register. */
    public function test_adding_a_team_from_the_register_still_goes_there(): void
    {
        $this->actingAs($this->user)
            ->post(route('teams.store'), ['name' => 'West Crew'])
            ->assertRedirect(route('teams.index'));
    }

    /**
     * Someone the crew does not have yet, recorded from the task step.
     *
     * A crew with no supervisor on it leaves that picker empty, and the planner
     * should not have to abandon a screen of typed task rows to fix it.
     */
    public function test_a_crew_member_can_be_added_from_the_task_step(): void
    {
        $this->actingAs($this->user)
            ->from(route('jobs.tasks.setup', $this->job))
            ->post(route('foremen.store'), [
                'name' => 'Alex Mercer',
                'role' => 'supervisor',
                'team_id' => $this->north->id,
                'inline' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('jobs.tasks.setup', $this->job));

        $added = Foreman::where('name', 'Alex Mercer')->sole();

        // Straight onto the job's crew, so the picker that offered the button
        // is the picker they land in.
        $this->assertSame('supervisor', $added->role);
        $this->assertSame($this->north->id, $added->team_id);
    }

    /** And the task step then offers them. */
    public function test_someone_added_that_way_is_offered_on_the_next_render(): void
    {
        $this->actingAs($this->user)
            ->from(route('jobs.tasks.setup', $this->job))
            ->post(route('foremen.store'), [
                'name' => 'Alex Mercer',
                'role' => 'supervisor',
                'team_id' => $this->north->id,
                'inline' => true,
            ]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->has('supervisors', 2)
                ->where('supervisors.0.name', 'Alex Mercer'));
    }

    /**
     * A job with no crew records people with no crew.
     *
     * Putting them on a team the job does not have would be inventing one.
     */
    public function test_someone_added_on_a_crewless_job_joins_no_crew(): void
    {
        $this->job->update(['team_id' => null]);

        $this->actingAs($this->user)
            ->from(route('jobs.tasks.setup', $this->job))
            ->post(route('foremen.store'), [
                'name' => 'Alex Mercer',
                'role' => 'foreman',
                'team_id' => null,
                'inline' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Foreman::where('name', 'Alex Mercer')->sole()->team_id);
    }
}
