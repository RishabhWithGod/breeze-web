<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The step straight after Create Job: laying the job out in tasks.
 *
 * The whole list is written in one request, so the rule these tests hold to is
 * all-or-nothing — a half-saved plan is worse than none, because the person who
 * typed it cannot tell which rows landed.
 */
class JobTaskSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);

        $client = $this->user->projects()->create([
            'name' => 'Harborview', 'client' => 'Harborview', 'status' => 'draft',
        ]);

        $this->job = Job::create([
            'project_id' => $client->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => '41 Harbor Way',
            'status' => 'planning',
        ]);
    }

    public function test_creating_a_job_lands_on_the_task_step(): void
    {
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Fit-out',
                'project_id' => $client->id,
                'address_ids' => [$site->id],
            ])
            ->assertRedirect(route('jobs.tasks.setup', Job::latest('id')->first()));
    }

    public function test_a_draft_is_not_sent_to_be_planned(): void
    {
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        // A draft is not ready for a plan, so it opens on the job itself.
        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Exploratory Retrofit',
                'project_id' => $client->id,
                'address_ids' => [$site->id],
                'save_as_draft' => true,
            ])
            ->assertRedirect(route('jobs.show', Job::latest('id')->first()));
    }

    public function test_the_whole_list_is_saved_in_one_go(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Survey the floor', 'category' => 'survey', 'estimated_hours' => 6],
                    ['title' => 'Rough-in first floor', 'category' => 'rough-in', 'priority' => 'high'],
                    ['title' => 'Final inspection'],
                ],
            ])
            ->assertRedirect(route('jobs.show', $this->job))
            ->assertSessionHas('success');

        $tasks = $this->job->refresh()->schedule->tasks;

        $this->assertCount(3, $tasks);
        // Saved in the order they were typed, not by name.
        $this->assertSame(
            ['Survey the floor', 'Rough-in first floor', 'Final inspection'],
            $tasks->pluck('title')->all(),
        );
        $this->assertSame('survey', $tasks[0]->category);
        $this->assertSame('high', $tasks[1]->priority);
        $this->assertSame(JobTask::STATUS_PENDING, $tasks[2]->status);
    }

    public function test_a_second_pass_appends_rather_than_renumbering(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor']],
        ]);

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Final inspection']],
        ]);

        $tasks = $this->job->refresh()->schedule->tasks;

        $this->assertSame(['Survey the floor', 'Final inspection'], $tasks->pluck('title')->all());
        $this->assertTrue($tasks[1]->position > $tasks[0]->position);
    }

    public function test_a_duplicate_name_is_reported_on_its_own_row_and_saves_nothing(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor']],
        ]);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Rough-in first floor'],
                    // Same name as the one already planned, in a different case.
                    ['title' => '  SURVEY THE FLOOR  '],
                ],
            ])
            ->assertSessionHasErrors('tasks.1.title');

        // All or nothing: the valid first row must not have landed either.
        $this->assertSame(1, $this->job->refresh()->schedule->tasks()->count());
    }

    public function test_two_rows_with_the_same_name_are_caught_before_the_database_is_touched(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Survey the floor'],
                    ['title' => 'Survey the floor'],
                ],
            ])
            ->assertSessionHasErrors('tasks.1.title');

        $this->assertNull($this->job->refresh()->schedule);
    }

    public function test_a_task_cannot_end_before_it_starts(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Survey the floor',
                    'starts_on' => '2026-09-10',
                    'ends_on' => '2026-09-01',
                ]],
            ])
            ->assertSessionHasErrors('tasks.0.ends_on');
    }

    public function test_the_step_shows_what_is_already_planned(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor', 'estimated_hours' => 6]],
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskSetup')
                ->where('job.name', 'Harborview Fit-out')
                ->has('existingTasks', 1)
                ->where('existingTasks.0.title', 'Survey the floor')
                ->has('categories')
                ->has('priorities'));
    }

    public function test_an_empty_list_is_refused_rather_than_saved_as_a_blank_plan(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), ['tasks' => []])
            ->assertSessionHasErrors('tasks');
    }
}
