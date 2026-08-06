<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Foreman $foreman;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->foreman = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
    }

    public function test_jobs_are_listed_with_their_foreman(): void
    {
        $this->makeJob(['name' => 'Harborview Data Hall']);

        $this->actingAs($this->user)
            ->get('/jobs')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Jobs')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Harborview Data Hall')
                ->where('jobs.data.0.foreman.initials', 'DW')
                ->has('foremen', 1));
    }

    public function test_jobs_can_be_searched_by_foreman_name(): void
    {
        $other = Foreman::create(['name' => 'Luis Ortega', 'initials' => 'LO']);
        $this->makeJob(['name' => 'Job A']);
        $this->makeJob(['name' => 'Job B', 'foreman_id' => $other->id]);

        $this->actingAs($this->user)
            ->get('/jobs?search=Ortega')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Job B'));
    }

    public function test_jobs_can_be_filtered_by_status(): void
    {
        $this->makeJob(['name' => 'Running', 'status' => 'in-progress']);
        $this->makeJob(['name' => 'Paused', 'status' => 'on-hold']);

        $this->actingAs($this->user)
            ->get('/jobs?status=on-hold')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Paused'));
    }

    public function test_a_job_can_be_created(): void
    {
        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Retail Fit-out',
                'client' => 'Northgate Retail',
                'location' => 'Northgate, Seattle',
                'job_type' => 'commercial',
                'foreman_id' => $this->foreman->id,
                'start_date' => '2026-05-11',
                'end_date' => '2026-09-04',
                'budget' => '42300',
            ])
            ->assertSessionHas('success');

        $job = Job::latest('id')->firstOrFail();

        // Intake opens the job's own screen, not the list.
        $this->assertDatabaseHas('work_jobs', [
            'id' => $job->id,
            'name' => 'Northgate Retail Fit-out',
            // Status is derived: a submitted form plans, a draft stays a draft.
            'status' => 'planning',
            'budget' => '42300.00',
        ]);
    }

    public function test_a_job_can_be_saved_as_a_draft(): void
    {
        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Exploratory Warehouse Retrofit',
                'client' => 'Pier 9 Logistics',
                'location' => 'Tacoma',
                'save_as_draft' => true,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('work_jobs', [
            'name' => 'Exploratory Warehouse Retrofit',
            'status' => 'draft',
        ]);
    }

    public function test_creating_a_job_validates_its_intake_fields(): void
    {
        $this->actingAs($this->user)
            ->from('/jobs/create')
            ->post('/jobs', [
                'name' => 'no',
                'foreman_id' => 9999,
                'start_date' => '2026-09-04',
                'end_date' => '2026-05-11',
                'budget' => '-5',
            ])
            ->assertSessionHasErrors([
                'name', 'client', 'location', 'foreman_id', 'end_date', 'budget',
            ]);

        $this->assertDatabaseCount('work_jobs', 0);
    }

    public function test_a_job_can_be_deleted_and_restored(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)
            ->from('/jobs')
            ->delete("/jobs/{$job->id}")
            ->assertRedirect('/jobs');

        $this->assertSoftDeleted($job);

        $this->actingAs($this->user)
            ->from('/jobs')
            ->post("/jobs/{$job->id}/restore")
            ->assertRedirect('/jobs');

        $this->assertNotSoftDeleted($job->fresh());
    }

    /** @param array<string, mixed> $attributes */
    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
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
