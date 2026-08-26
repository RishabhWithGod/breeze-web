<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\Upload;
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

    public function test_the_create_screen_offers_takeoff_projects_and_their_drawings(): void
    {
        [$project, $upload] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->get('/jobs/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobCreate')
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->has('uploads', 1)
                ->where('uploads.0.id', $upload->id)
                ->where('uploads.0.projectId', $project->id)
                ->where('uploads.0.estimate', null));
    }

    public function test_creating_a_job_links_it_to_the_selected_project_and_drawing(): void
    {
        [$project, $upload, $result] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Retail Fit-out',
                'client' => 'Northgate Retail',
                'location' => 'Northgate, Seattle',
                'project_id' => $project->id,
                'upload_id' => $upload->id,
            ])
            ->assertSessionHas('success');

        $job = Job::latest('id')->firstOrFail();

        $this->assertSame($project->id, $job->project_id);
        $this->assertSame($result->id, $job->ai_result_id);
    }

    public function test_creating_a_job_links_the_drawings_existing_estimate_instead_of_duplicating_it(): void
    {
        [$project, $upload, $result] = $this->makeTakeoffDrawing();
        $estimate = Estimate::create([
            'ai_result_id' => $result->id,
            'number' => 'EST-9001',
            'client' => 'Northgate Retail',
            'project' => 'Northgate Fit-out',
            'issued_on' => now()->toDateString(),
            'amount' => 5000,
            'status' => 'draft',
        ]);
        $result->update(['estimate_id' => $estimate->id]);

        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Retail Fit-out',
                'client' => 'Northgate Retail',
                'location' => 'Northgate, Seattle',
                'project_id' => $project->id,
                'upload_id' => $upload->id,
                // Ticked regardless — the linked estimate takes priority over this.
                'create_estimate' => true,
            ])
            ->assertSessionHas('success');

        $job = Job::latest('id')->firstOrFail();

        $this->assertSame($job->id, $estimate->fresh()->job_id);
        $this->assertDatabaseCount('estimates', 1);
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

    /**
     * A project with one uploaded drawing already run through the AI engine —
     * what the Project/PDF pickers on the create screens link back to.
     *
     * @return array{0: Project, 1: Upload, 2: AiResult}
     */
    private function makeTakeoffDrawing(): array
    {
        $project = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Northgate Fit-out',
            'client' => 'Northgate Retail',
            'status' => 'completed',
        ]);
        $upload = Upload::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'name' => 'northgate-electrical.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
        ]);

        return [$project, $upload, $result];
    }
}
