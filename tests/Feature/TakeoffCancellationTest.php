<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cancelling a takeoff run: the uploaded drawing is actually removed (row and
 * file), and a response that arrives after the cancel — the engine call was
 * already in flight — can never resurrect the run or write against a drawing
 * that no longer exists.
 *
 * Uses `TakeoffOrchestrator` directly rather than `POST /ai-takeoff/upload`:
 * that endpoint depends on a real reachable AI engine and unrelated project
 * validation this test isn't about.
 */
class TakeoffCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Upload $upload;

    private AiJob $aiJob;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->project = $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'processing',
        ]);

        $path = 'uploads/drawing.pdf';
        Storage::disk('local')->put($path, 'fake pdf contents');
        $this->upload = $this->project->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'drawing.pdf',
            'format' => 'PDF',
            'size_bytes' => 17,
            'path' => $path,
            'status' => 'processing',
        ]);

        $this->aiJob = AiJob::create([
            'project_id' => $this->project->id,
            'upload_id' => $this->upload->id,
            'user_id' => $this->user->id,
            'status' => AiJob::STATUS_PROCESSING,
            'queued_at' => now(),
        ]);
    }

    public function test_cancelling_removes_the_uploaded_drawings_row_and_file(): void
    {
        $this->assertTrue(Storage::disk('local')->exists($this->upload->path));

        app(TakeoffOrchestrator::class)->cancel($this->aiJob);

        $this->assertSame(AiJob::STATUS_CANCELLED, $this->aiJob->fresh()->status);
        $this->assertSame('failed', $this->project->fresh()->status);
        $this->assertDatabaseMissing('uploads', ['id' => $this->upload->id]);
        $this->assertFalse(Storage::disk('local')->exists($this->upload->path));
    }

    /** A worker that had already claimed the job and was mid-engine-call is unaffected until it checks back in. */
    public function test_cancelling_does_not_disturb_a_run_already_in_flight_until_it_checks_back_in(): void
    {
        app(TakeoffOrchestrator::class)->cancel($this->aiJob);

        // The response the (now-orphaned) engine call eventually returns.
        $result = app(TakeoffOrchestrator::class)->ingest($this->aiJob, $this->minimalEnginePayload());

        $this->assertNull($result);
        $this->assertSame(AiJob::STATUS_CANCELLED, $this->aiJob->fresh()->status);
        $this->assertSame(0, AiResult::where('ai_job_id', $this->aiJob->id)->count());
    }

    /** A late failure (e.g. the in-flight call itself errored) must not overwrite an already-cancelled run. */
    public function test_a_failure_reported_after_cancellation_does_not_overwrite_it(): void
    {
        app(TakeoffOrchestrator::class)->cancel($this->aiJob);

        app(TakeoffOrchestrator::class)->fail($this->aiJob, 'the stored drawing is missing.');

        $this->assertSame(AiJob::STATUS_CANCELLED, $this->aiJob->fresh()->status);
        $this->assertNull($this->aiJob->fresh()->error_message);
    }

    /** @return array<string, mixed> */
    private function minimalEnginePayload(): array
    {
        return [
            'run_id' => 'run-123',
            'project_name' => 'Harborview Data Hall',
            'pages' => 1,
            'cards' => [],
            'confidence' => 0.9,
            'processing_time' => 1.0,
            'pipeline_status' => [],
            'warnings' => [],
            'symbol_counts' => [],
            'estimate' => [],
        ];
    }
}
