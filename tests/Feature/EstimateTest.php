<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
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
}
