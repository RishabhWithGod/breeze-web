<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Once a job is completed, `Job::isLocked()` says nothing about it may change
 * again. This pins that promise across every surface that touches a job —
 * not just `JobController::update()`, which is the obvious one.
 */
class CompletedJobLockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Foreman $foreman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->foreman = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
    }

    public function test_a_completed_job_cannot_be_updated(): void
    {
        $client = Client::create(['user_id' => $this->user->id, 'name' => 'Acme Co']);
        $project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'name' => 'Acme HQ',
            'client' => 'Acme Co',
            'status' => 'draft',
        ]);
        $address = $client->addresses()->create(['address' => '123 Main St']);
        $job = $this->makeJob(['status' => 'completed', 'project_id' => $project->id]);

        $this->actingAs($this->user)
            ->put("/jobs/{$job->id}", [
                'name' => 'Renamed Job',
                'client_id' => $client->id,
                'project_id' => $project->id,
                'address_ids' => [$address->id],
                'job_type' => 'commercial',
                'status' => 'in-progress',
                'start_date' => '2026-01-01',
                'end_date' => '2026-06-01',
            ])
            ->assertStatus(409);

        $this->assertSame('A Job', $job->fresh()->name);
    }

    public function test_a_completed_job_cannot_be_deleted(): void
    {
        $job = $this->makeJob(['status' => 'completed']);

        $this->actingAs($this->user)
            ->delete("/jobs/{$job->id}")
            ->assertStatus(409);

        $this->assertNotSoftDeleted($job);
    }

    public function test_visiting_the_edit_screen_of_a_completed_job_redirects_back_instead(): void
    {
        $job = $this->makeJob(['status' => 'completed']);

        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}/edit")
            ->assertRedirect("/jobs/{$job->id}")
            ->assertSessionHas('warning');
    }

    public function test_the_show_screen_still_works_for_a_completed_job(): void
    {
        $job = $this->makeJob(['status' => 'completed']);

        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobShow')
                ->where('job.isLocked', true));
    }

    public function test_an_active_job_still_reports_unlocked(): void
    {
        $job = $this->makeJob(['status' => 'in-progress']);

        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page->where('job.isLocked', false));

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(fn (Assert $page) => $page->where('jobs.data.0.isLocked', false));
    }

    public function test_bulk_delete_skips_completed_jobs_but_still_deletes_the_rest(): void
    {
        $locked = $this->makeJob(['status' => 'completed']);
        $active = $this->makeJob(['status' => 'in-progress']);

        $this->actingAs($this->user)
            ->post('/jobs/bulk', ['ids' => [$locked->id, $active->id], 'action' => 'delete'])
            ->assertSessionHas('warning');

        $this->assertNotSoftDeleted($locked);
        $this->assertSoftDeleted($active);
    }

    public function test_bulk_status_change_skips_completed_jobs(): void
    {
        $locked = $this->makeJob(['status' => 'completed']);
        $active = $this->makeJob(['status' => 'in-progress']);

        $this->actingAs($this->user)
            ->post('/jobs/bulk', ['ids' => [$locked->id, $active->id], 'action' => 'status', 'status' => 'on-hold'])
            ->assertSessionHas('warning');

        $this->assertSame('completed', $locked->fresh()->status);
        $this->assertSame('on-hold', $active->fresh()->status);
    }

    public function test_a_crew_member_cannot_be_assigned_a_role_on_a_completed_job(): void
    {
        $job = $this->makeJob(['status' => 'completed']);
        $member = TeamMember::create(['name' => 'Aisha Bello', 'initials' => 'AB', 'role' => 'Electrician']);

        $this->actingAs($this->user)
            ->post("/jobs/{$job->id}/assignments", [
                'role' => 'electrician',
                'team_member_id' => $member->id,
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('job_assignments', 0);
    }

    public function test_a_crew_member_cannot_be_added_to_a_completed_jobs_team(): void
    {
        $job = $this->makeJob(['status' => 'completed']);
        $member = TeamMember::create(['name' => 'Marco Ruiz', 'initials' => 'MR', 'role' => 'Electrician']);

        $this->actingAs($this->user)
            ->post("/jobs/{$job->id}/team", ['team_member_id' => $member->id])
            ->assertStatus(409);

        $this->assertSame(0, $job->teamMembers()->count());
    }

    public function test_a_completed_jobs_schedule_cannot_be_updated(): void
    {
        $job = $this->makeJob(['status' => 'completed']);
        app(ScheduleBuilder::class)->build($job, $this->user, withTasks: false);

        $this->actingAs($this->user)
            ->put("/jobs/{$job->id}/schedule", [
                'starts_on' => '2026-10-05',
                'ends_on' => '2026-12-20',
                'working_days' => [1, 2, 3, 4, 5],
                'work_start_time' => '08:00',
                'work_end_time' => '16:30',
                'break_minutes' => 30,
                'timezone' => 'UTC',
                'holidays' => [],
                'status' => JobSchedule::STATUS_PUBLISHED,
            ])
            ->assertStatus(409);
    }

    public function test_a_cost_entry_cannot_be_recorded_against_a_completed_job(): void
    {
        $job = $this->makeJob(['status' => 'completed']);

        $this->actingAs($this->user)
            ->post("/jobs/{$job->id}/costing/entries", [
                'category' => 'material',
                'description' => 'Extra conduit',
                'amount' => 250,
                'incurred_on' => '2026-01-15',
            ])
            ->assertStatus(409);

        $this->assertDatabaseCount('job_cost_entries', 0);
    }

    public function test_task_planning_screens_redirect_away_from_a_completed_job(): void
    {
        $job = $this->makeJob(['status' => 'completed']);
        $schedule = app(ScheduleBuilder::class)->build($job, $this->user, withTasks: false);
        $task = $schedule->tasks()->create([
            'job_id' => $job->id,
            'title' => 'Rough-in wiring',
            'status' => 'pending',
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}/tasks/setup")
            ->assertRedirect("/jobs/{$job->id}")
            ->assertSessionHas('warning');

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}/edit")
            ->assertRedirect("/jobs/{$job->id}")
            ->assertSessionHas('warning');
    }

    /**
     * A task can be completed while the rest of the job is still open — that
     * one task is frozen the same way a completed job is, even though the
     * job itself is still editable.
     */
    public function test_a_completed_task_cannot_be_edited_or_removed_via_the_task_setup_screen(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->user, withTasks: false);
        $task = $schedule->tasks()->create([
            'job_id' => $job->id,
            'title' => 'Rough-in wiring',
            'status' => 'completed',
            'foreman_id' => $this->foreman->id,
            'supervisor_id' => $this->foreman->id,
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->get("/tasks/{$task->id}/edit")
            ->assertRedirect("/jobs/{$job->id}")
            ->assertSessionHas('warning');

        $this->actingAs($this->user)
            ->put("/tasks/{$task->id}", [
                'title' => 'Renamed task',
                'status' => 'in-progress',
                'foreman_id' => $this->foreman->id,
                'supervisor_id' => $this->foreman->id,
                'estimate_item_ids' => [],
            ])
            ->assertStatus(409);

        $this->actingAs($this->user)
            ->delete("/tasks/{$task->id}")
            ->assertStatus(409);

        $this->assertSame('Rough-in wiring', $task->fresh()->title);
    }

    /** The Schedule screen's own delete route enforces the same rule. */
    public function test_a_completed_task_cannot_be_deleted_from_the_schedule_screen(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, $this->user, withTasks: false);
        $task = $schedule->tasks()->create([
            'job_id' => $job->id,
            'title' => 'Panel install',
            'status' => 'completed',
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->delete("/schedule-tasks/{$task->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('job_tasks', ['id' => $task->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'project_id' => $this->user->projects()->create([
                'name' => 'Test Project', 'client' => 'A Job', 'status' => 'draft',
            ])->id,
            'name' => 'A Job',
            'status' => 'in-progress',
            'foreman_id' => $this->foreman->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-06-01',
            'budget' => 1000,
            ...$attributes,
        ]);
    }
}
