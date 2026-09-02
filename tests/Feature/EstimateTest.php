<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Create Estimate screen.
 *
 * The client is picked from the client register rather than typed: clients are
 * projects, so `project_id` names the client and the estimate's own
 * `client`/`project` columns are snapshots the server writes from it.
 */
class EstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_an_estimate_can_be_created_without_any_takeoff_drawing(): void
    {
        [$project] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->post('/estimates', [
                'project_id' => $project->id,
                'issued_on' => '2026-08-10',
                'amount' => '24850',
                'status' => 'draft',
            ])
            ->assertSessionHas('success');

        $estimate = Estimate::latest('id')->firstOrFail();
        // Both name columns are snapshots of the picked client, not typed input.
        $this->assertSame('Northgate Fit-out', $estimate->client);
        $this->assertSame('Northgate Fit-out', $estimate->project);
        $this->assertSame($project->id, $estimate->project_id);
        $this->assertNull($estimate->ai_result_id);
    }

    public function test_an_estimate_cannot_be_created_without_a_client(): void
    {
        $this->actingAs($this->user)
            ->post('/estimates', [
                'issued_on' => '2026-08-10',
                'amount' => '24850',
                'status' => 'draft',
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertDatabaseCount('estimates', 0);
    }

    public function test_the_create_screen_offers_the_client_register_and_their_drawings(): void
    {
        [$project, $upload] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->get('/estimates/create')
            ->assertInertia(fn (Assert $page) => $page
                ->component('EstimateCreate')
                ->has('clients', 1)
                ->where('clients.0.id', $project->id)
                ->where('clients.0.name', $project->name)
                ->has('uploads', 1)
                ->where('uploads.0.id', $upload->id)
                ->where('uploads.0.estimate', null));
    }

    public function test_creating_an_estimate_links_it_to_the_selected_project_and_drawing(): void
    {
        [$project, $upload, $result] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->post('/estimates', [
                'issued_on' => '2026-08-10',
                'amount' => '5000',
                'status' => 'draft',
                'project_id' => $project->id,
                'upload_id' => $upload->id,
            ])
            ->assertSessionHas('success');

        $estimate = Estimate::latest('id')->firstOrFail();

        $this->assertSame($project->id, $estimate->project_id);
        $this->assertSame($result->id, $estimate->ai_result_id);
        $this->assertSame($estimate->id, $result->fresh()->estimate_id);
    }

    public function test_picking_a_drawing_that_already_has_an_estimate_opens_it_instead_of_duplicating_it(): void
    {
        [$project, $upload, $result] = $this->makeTakeoffDrawing();
        $existing = Estimate::create([
            'ai_result_id' => $result->id,
            'number' => 'EST-9001',
            'client' => 'Northgate Retail',
            'project' => 'Northgate Fit-out',
            'issued_on' => now()->toDateString(),
            'amount' => 5000,
            'status' => 'draft',
        ]);
        $result->update(['estimate_id' => $existing->id]);

        $this->actingAs($this->user)
            ->post('/estimates', [
                'issued_on' => '2026-08-10',
                'amount' => '5000',
                'status' => 'draft',
                'project_id' => $project->id,
                'upload_id' => $upload->id,
            ])
            ->assertRedirect("/estimates/{$existing->id}/edit")
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('estimates', 1);
    }

    /**
     * A project with one uploaded drawing already run through the AI engine.
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

    public function test_the_estimate_screen_shows_the_roadmap_only_when_the_flow_sent_you(): void
    {
        [$project] = $this->makeTakeoffDrawing();

        $estimate = Estimate::create([
            'project_id' => $project->id,
            'number' => 'EST-5001',
            'client' => $project->name,
            'project' => $project->name,
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            'status' => 'draft',
        ]);

        // Opened from a job or the estimates list: a record to read.
        $this->actingAs($this->user)
            ->get(route('estimates.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page->where('inFlow', false));

        // Handed over by the flow itself: a step, with its roadmap.
        $this->actingAs($this->user)
            ->get(route('estimates.show', ['estimate' => $estimate, 'flow' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('inFlow', true));
    }

    public function test_back_from_an_estimate_returns_to_the_job_it_was_opened_from(): void
    {
        $job = Job::create([
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => '41 Harbor Way',
            'status' => 'planning',
        ]);
        $estimate = Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-6001',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->get(route('estimates.show', ['estimate' => $estimate, 'from_job' => $job->id]))
            ->assertInertia(fn (Assert $page) => $page->where('backUrl', route('jobs.show', $job)));

        // Editing keeps the trail, so the way out stays one screen at a time.
        $this->actingAs($this->user)
            ->get(route('estimates.edit', ['estimate' => $estimate, 'from_job' => $job->id]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'backUrl',
                route('estimates.show', ['estimate' => $estimate->id, 'from_job' => $job->id]),
            ));
    }

    public function test_a_job_that_does_not_own_the_estimate_is_not_believed(): void
    {
        $theirJob = Job::create([
            'name' => 'Someone Else', 'client' => 'Someone Else',
            'location' => 'Elsewhere', 'status' => 'planning',
        ]);
        $estimate = Estimate::create([
            'number' => 'EST-6002', 'client' => 'Harborview', 'project' => 'Harborview',
            'issued_on' => now()->toDateString(), 'amount' => 500, 'status' => 'draft',
        ]);

        // An id that does not own this estimate is somebody guessing at a URL,
        // not navigation — so Back falls back to the list.
        $this->actingAs($this->user)
            ->get(route('estimates.show', ['estimate' => $estimate, 'from_job' => $theirJob->id]))
            ->assertInertia(fn (Assert $page) => $page->where('backUrl', route('estimates.index')));
    }

    public function test_saving_an_edit_keeps_the_trail_back_to_the_job(): void
    {
        $job = Job::create([
            'name' => 'Harborview Fit-out', 'client' => 'Harborview',
            'location' => '41 Harbor Way', 'status' => 'planning',
        ]);
        $estimate = Estimate::create([
            'job_id' => $job->id,
            'project_id' => $this->makeTakeoffDrawing()[0]->id,
            'number' => 'EST-7001',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            'status' => 'draft',
        ]);

        // The edit form posts to a URL carrying the origin, because a PUT has
        // no query string of its own to inherit it from.
        $this->actingAs($this->user)
            ->get(route('estimates.edit', ['estimate' => $estimate, 'from_job' => $job->id]))
            ->assertInertia(fn (Assert $page) => $page->where(
                'saveUrl',
                route('estimates.update', ['estimate' => $estimate->id, 'from_job' => $job->id]),
            ));

        $this->actingAs($this->user)
            ->put(route('estimates.update', ['estimate' => $estimate, 'from_job' => $job->id]), [
                'project_id' => $estimate->project_id,
                'status' => 'sent',
                'issued_on' => now()->toDateString(),
                'markup_pct' => 0,
                'tax_pct' => 0,
            ])
            // Back on the estimate, still knowing where it came from.
            ->assertRedirect(route('estimates.show', [
                'estimate' => $estimate->id,
                'from_job' => $job->id,
            ]));
    }

    public function test_an_estimate_raised_against_a_job_is_not_a_draft(): void
    {
        [$project, $upload] = $this->makeTakeoffDrawing();
        $site = $project->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $existing = Estimate::create([
            'ai_result_id' => $project->latestAiResult->id,
            'number' => 'EST-8001',
            'client' => $project->name,
            'project' => $project->name,
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            // Raised at sign-off, before any job existed.
            'status' => 'draft',
        ]);
        $project->latestAiResult->update(['estimate_id' => $existing->id]);

        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Harborview Fit-out',
            'project_id' => $project->id,
            'address_ids' => [$site->id],
            'upload_id' => $upload->id,
        ])->assertSessionHas('success');

        /*
         * Raising the job is the act of accepting the estimate — a crew is
         * being scheduled against it, so calling it a draft says the opposite.
         */
        $this->assertSame(Estimate::STATUS_FOR_A_LIVE_JOB, $existing->refresh()->status);
        $this->assertNotNull($existing->job_id);
    }

    public function test_an_estimate_with_no_job_behind_it_is_still_a_draft(): void
    {
        [$project] = $this->makeTakeoffDrawing();

        $this->actingAs($this->user)
            ->post('/estimates', [
                'project_id' => $project->id,
                'issued_on' => now()->toDateString(),
                'amount' => '500',
                'status' => 'draft',
            ])
            ->assertSessionHas('success');

        $this->assertSame('draft', Estimate::latest('id')->first()->status);
    }
}
