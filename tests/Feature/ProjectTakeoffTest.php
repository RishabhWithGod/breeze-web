<?php

namespace Tests\Feature;

use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\AiJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Starting an AI takeoff run against a drawing already on record for a
 * project — no separate upload step, unlike the standalone `/ai-takeoff/upload`
 * flow which always creates its own project from scratch.
 */
class ProjectTakeoffTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake(config('takeoff.uploads.disk'));
    }

    public function test_starting_a_takeoff_queues_the_run_and_goes_straight_to_processing(): void
    {
        Queue::fake();

        $project = $this->projectWithDrawing();

        $response = $this->actingAs($this->user)->post(route('projects.takeoff.start', $project));

        $response->assertRedirect(route('processing.show', $project));

        $this->assertSame('processing', $project->refresh()->status);
        $this->assertSame(1, $project->aiJobs()->count());
        $this->assertSame(AiJob::STATUS_QUEUED, $project->latestAiJob->status);

        // No second project was created — the run uses the drawing already on file.
        $this->assertSame(1, Project::count());

        Queue::assertPushed(RenderDrawingPreviews::class);
        Queue::assertPushed(ProcessTakeoffRun::class);
    }

    public function test_it_refuses_to_start_without_a_drawing_on_file(): void
    {
        Queue::fake();

        $project = $this->makeProject();

        $this->actingAs($this->user)
            ->post(route('projects.takeoff.start', $project))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame(0, $project->aiJobs()->count());
        Queue::assertNothingPushed();
    }

    public function test_it_refuses_to_start_when_the_engine_is_not_configured(): void
    {
        Queue::fake();
        config(['ai.base_url' => null]);

        $project = $this->projectWithDrawing();

        $this->actingAs($this->user)
            ->post(route('projects.takeoff.start', $project))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame(0, $project->aiJobs()->count());
        Queue::assertNothingPushed();
    }

    public function test_starting_it_again_while_a_run_is_in_flight_just_watches_the_same_run(): void
    {
        Queue::fake();

        $project = $this->projectWithDrawing();

        $this->actingAs($this->user)->post(route('projects.takeoff.start', $project));
        $firstJobId = $project->refresh()->latestAiJob->id;

        $this->actingAs($this->user)
            ->post(route('projects.takeoff.start', $project))
            ->assertRedirect(route('processing.show', $project));

        $this->assertSame(1, $project->aiJobs()->count());
        $this->assertSame($firstJobId, $project->refresh()->latestAiJob->id);
    }

    private function projectWithDrawing(): Project
    {
        $project = $this->makeProject();

        $project->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => UploadedFile::fake()->create('E-101.pdf', 60, 'application/pdf')->getSize(),
            'path' => 'uploads/E-101.pdf',
            'status' => 'completed',
        ]);

        return $project;
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeProject(array $attributes = []): Project
    {
        return $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Vertex Infrastructure',
            'status' => 'draft',
            'review_status' => 'none',
            ...$attributes,
        ]);
    }
}
