<?php

namespace Tests\Feature;

use App\Jobs\BackfillTakeoffCrops;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use App\Services\Ai\ArtefactStore;
use App\Support\UploadLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\TalksToTheEngine;
use Tests\TestCase;

/**
 * Upload → the real AI engine → reviewable rows.
 *
 * These tests post a real drawing to the running engine and assert against the
 * response it actually returns. Nothing is faked; when the engine is not running
 * the engine-dependent tests skip.
 */
class TakeoffFlowTest extends TestCase
{
    use RefreshDatabase, TalksToTheEngine;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Note: no Storage::fake — drawings must be real files on disk for the
        // engine to receive them.
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->deleteTestArtefacts();
        parent::tearDown();
    }

    public function test_the_upload_screen_reports_the_engines_readiness(): void
    {
        $this->skipUnlessEngineIsUp();

        $this->actingAs($this->user)
            ->get('/ai-takeoff/upload')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Upload')
                ->where('limits.maxFiles', config('takeoff.uploads.max_files'))
                // The advertised limit is the one actually enforced: PHP's ceiling
                // sits under the product's setting.
                ->where('limits.maxFileSizeMb', UploadLimits::effectiveMb())
                ->where('aiConfigured', true)
                // Readiness is polled, not rendered: a page render must never wait
                // on the engine.
                ->where('engineStatusUrl', route('ai.engine-status')));
    }

    public function test_engine_readiness_is_reported_by_its_own_endpoint(): void
    {
        $this->skipUnlessEngineIsUp();

        $this->actingAs($this->user)
            ->getJson('/ai-takeoff/engine-status')
            ->assertOk()
            ->assertJson(['configured' => true, 'online' => true]);
    }

    public function test_a_stalled_run_is_requeued_rather_than_left_hanging(): void
    {
        $this->skipUnlessEngineIsUp();
        // No worker: the job stays queued, exactly as it would on a machine where
        // nobody started one.
        Queue::fake();

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();
        $aiJob = AiJob::latest('id')->firstOrFail();

        // Fresh runs are left alone; only an untouched one is rescued.
        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status")
            ->assertJson(['awaitingWorker' => false]);

        $aiJob->forceFill([
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status")
            ->assertOk()
            ->assertJson(['status' => AiJob::STATUS_QUEUED, 'awaitingWorker' => true]);

        // Rescued by re-queueing — never by running the analysis in the request.
        Queue::assertPushed(ProcessTakeoffRun::class, 2);

        $this->actingAs($this->user)
            ->from("/processing/{$project->id}")
            ->post("/processing/{$project->id}/retry")
            ->assertRedirect("/processing/{$project->id}");

        Queue::assertPushed(ProcessTakeoffRun::class, 3);
    }

    /**
     * The rescue must not fire on every poll.
     *
     * It once did, and it filled the queue: the requeue wrote the same `stage` the
     * row already had, so Eloquent found nothing dirty, wrote nothing, and left
     * `updated_at` stale — which is the very thing that decides a run is stalled. A
     * screen left open queued a duplicate analysis every 2.5 seconds.
     */
    public function test_polling_a_stalled_run_requeues_it_once_not_on_every_poll(): void
    {
        $this->skipUnlessEngineIsUp();
        Queue::fake();

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();
        $aiJob = AiJob::latest('id')->firstOrFail();

        $aiJob->forceFill(['updated_at' => now()->subMinute()])->save();

        // The rescue itself.
        $this->actingAs($this->user)->getJson("/processing/{$project->id}/status")->assertOk();
        Queue::assertPushed(ProcessTakeoffRun::class, 2);

        // Six more polls within the window: the run is still queued and still
        // unclaimed, but it has just been rescued, so nothing more is pushed.
        for ($poll = 0; $poll < 6; $poll++) {
            $this->actingAs($this->user)->getJson("/processing/{$project->id}/status")->assertOk();
        }

        Queue::assertPushed(ProcessTakeoffRun::class, 2);
        $this->assertSame(1, $aiJob->fresh()->poll_attempts);
    }

    /** Re-queueing cannot conjure a worker, so it stops rather than filling the queue. */
    public function test_a_run_nothing_ever_claims_stops_being_requeued(): void
    {
        $this->skipUnlessEngineIsUp();
        Queue::fake();

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();
        $aiJob = AiJob::latest('id')->firstOrFail();
        $aiJob->forceFill(['created_at' => now()->subMinutes(5)])->save();

        // Each poll only rescues once the previous rescue has gone quiet, so the
        // clock is wound back between them.
        for ($poll = 0; $poll < 20; $poll++) {
            $aiJob->fresh()->forceFill(['updated_at' => now()->subMinute()])->save();
            $this->actingAs($this->user)->getJson("/processing/{$project->id}/status")->assertOk();
        }

        // The upload's own dispatch, plus a bounded number of rescues.
        Queue::assertPushed(ProcessTakeoffRun::class, 9);

        // Still reported as waiting, so the screen can ask rather than spin.
        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status")
            ->assertJson(['awaitingWorker' => true]);
    }

    /**
     * The engine is one uvicorn worker, so two analyses must take turns.
     *
     * Without this a second drawing does not run in parallel — it queues inside the
     * engine and blocks the cheap endpoints the pipeline needs while it waits.
     */
    public function test_only_one_analysis_may_hold_the_engine_at_a_time(): void
    {
        // This lock exists to serialise turns on the real engine — it has
        // nothing to protect when static takeoff mode answers from a DB
        // lookup instead (see `ProcessTakeoffRun::middleware()`), so this
        // test pins the flag it actually means to exercise.
        config(['static_takeoff.enabled' => false]);

        $middleware = collect((new ProcessTakeoffRun(1))->middleware());

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware->first());

        // Attempts have to outlast the wait, or a queued run fails instead of
        // starting once the engine frees up.
        $this->assertGreaterThan(1, (new ProcessTakeoffRun(1))->tries);
    }

    /** Page previews are queued beside the run, never rendered inside it. */
    public function test_previews_are_rendered_off_the_runs_critical_path(): void
    {
        $this->skipUnlessEngineIsUp();
        Queue::fake();

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);

        Queue::assertPushed(RenderDrawingPreviews::class, 1);
        Queue::assertPushed(ProcessTakeoffRun::class, 1);
    }

    /**
     * A poll that already knows the current state is held, not answered at once.
     *
     * This is what keeps a minute-long run to a handful of requests rather than one
     * every couple of seconds.
     */
    public function test_a_poll_waits_for_a_change_instead_of_repeating_itself(): void
    {
        $this->skipUnlessEngineIsUp();
        Queue::fake();
        config(['takeoff.polling.hold_seconds' => 2, 'takeoff.polling.tick_ms' => 200]);

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();

        $first = $this->actingAs($this->user)->getJson("/processing/{$project->id}/status");
        $signature = $first->json('signature');

        $this->assertNotSame('', $signature);

        // Nothing moves the run on, so the request should spend its whole budget
        // waiting rather than returning immediately.
        $began = microtime(true);
        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status?since=".urlencode($signature))
            ->assertOk()
            ->assertJson(['signature' => $signature]);

        $this->assertGreaterThanOrEqual(1.5, microtime(true) - $began);
    }

    /** A finished run answers at once — there is nothing left to wait for. */
    public function test_a_poll_on_a_finished_run_returns_without_waiting(): void
    {
        $this->skipUnlessEngineIsUp();
        config(['takeoff.polling.hold_seconds' => 10, 'takeoff.polling.tick_ms' => 200]);

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();
        $aiJob = AiJob::latest('id')->firstOrFail();
        $aiJob->update(['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100, 'stage' => 'completed']);

        $began = microtime(true);
        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status?since=".urlencode('succeeded|100|completed'))
            ->assertOk()
            ->assertJson(['finished' => true]);

        $this->assertLessThan(2, microtime(true) - $began);
    }

    public function test_a_crop_that_is_not_filed_yet_queues_a_backfill_instead_of_calling_the_engine(): void
    {
        $result = $this->ingestRealAnalysis($this->user);
        Queue::fake();

        $review = $result->reviews()->firstOrFail();
        $review->update(['crop_path' => null, 'image_path' => 'lifecycle/crop_0001.png']);

        // 404 now, filled in by the background job — never an inline engine fetch.
        $this->actingAs($this->user)
            ->get("/reviews/{$result->id}/crops/{$review->id}")
            ->assertNotFound();

        Queue::assertPushed(BackfillTakeoffCrops::class);
    }

    public function test_a_drawing_cannot_be_submitted_while_the_engine_is_unconfigured(): void
    {
        config(['ai.base_url' => null]);

        $this->actingAs($this->user)
            ->from('/ai-takeoff/upload')
            ->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]])
            ->assertSessionHasErrors('files');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_uploading_a_drawing_queues_the_analysis(): void
    {
        $this->skipUnlessEngineIsUp();
        // Faking the queue keeps this test about the request, not the analysis.
        Queue::fake();

        $response = $this->actingAs($this->user)->post('/ai-takeoff/upload', [
            'files' => [$this->fixtureUpload()],
        ]);

        $project = Project::latest('id')->firstOrFail();
        $response->assertRedirect("/processing/{$project->id}");

        // Notes belong to the client and are set on its own screen — an upload
        // attaches a drawing, it does not rewrite the client's record.
        $this->assertCount(1, $project->uploads);
        $this->assertTrue(
            app(ArtefactStore::class)->exists($project->primaryUpload->path)
        );

        $aiJob = AiJob::latest('id')->firstOrFail();
        $this->assertSame(AiJob::STATUS_QUEUED, $aiJob->status);
        $this->assertSame('sample-drawing.pdf', $aiJob->upload->name);

        Queue::assertPushed(ProcessTakeoffRun::class);
    }

    public function test_the_engine_analyses_a_real_drawing_and_the_response_is_ingested_whole(): void
    {
        $result = $this->ingestRealAnalysis($this->user);
        $payload = $this->engineResponse();

        /*
         * Stored verbatim, before anything is derived from it. Compared loosely
         * because a JSON round-trip narrows whole floats (21323.0 → 21323); the
         * filed copy below is the byte-level guarantee.
         */
        $this->assertEquals($payload, $result->original_payload);

        $store = app(ArtefactStore::class);
        $this->assertTrue($store->exists($result->original_path));
        $this->assertEquals(
            $payload,
            json_decode($store->disk()->get($result->original_path), true),
        );

        // Scalars and maps land on the result.
        $this->assertSame($payload['project_name'], $result->project_name);
        $this->assertSame($payload['pages'], $result->page_count);
        /*
         * Compared by content, not key order. MySQL's JSON type stores an object in
         * its own key order rather than the order it was handed, and nothing reads
         * these maps positionally — `pipelineStages()` imposes the display order,
         * and the counts are looked up by name. Asserting order here would only
         * pin down which database is underneath.
         */
        $this->assertEquals($payload['pipeline_status'], $result->pipeline_status);
        $this->assertSame($payload['warnings'], $result->warnings);
        $this->assertEquals($payload['symbol_counts'], $result->symbol_counts);
        $this->assertEqualsWithDelta($payload['processing_time'], $result->processing_time, 0.01);
        $this->assertSame(
            (float) $payload['estimate']['grand_total'],
            (float) $result->ai_estimate['grand_total'],
        );

        // One review card per symbol type, plus one per needs-review observation.
        $this->assertSame(
            count($payload['symbols']) + count($payload['needs_review']),
            $result->reviews()->count(),
        );

        // The repeated sections get their own rows.
        $this->assertSame(count($payload['wire_sizes']), $result->wireSizes()->count());
        $this->assertSame(count($payload['boq']), $result->boqLines()->count());
        $this->assertSame(count($payload['panel_schedules']), $result->panelSchedules()->count());
        $this->assertSame(count($payload['equipment']), $result->equipment()->count());
        $this->assertSame(count($payload['circuits']), $result->circuits()->count());

        // Reviewed values start as the engine's, with its evidence intact.
        $first = $result->reviews()->where('origin', 'symbol')->firstOrFail();
        $engineSymbol = collect($payload['symbols'])->firstWhere('name', $first->ai_name);
        $this->assertNotNull($engineSymbol);
        $this->assertSame($engineSymbol['count'], $first->ai_count);
        $this->assertSame($engineSymbol['count'], $first->final_count);
        $this->assertSame($engineSymbol['evidence'] ?? [], $first->evidence);
        $this->assertEqualsWithDelta($engineSymbol['confidence'], $first->confidence, 0.001);

        $this->assertSame('completed', $result->project->fresh()->status);
        $this->assertSame(AiResult::REVIEW_PENDING, $result->review_status);
    }

    public function test_the_engines_lifecycle_supplies_crops_bounding_boxes_and_stages(): void
    {
        $result = $this->ingestRealAnalysis($this->user);

        if (blank($result->run_id)) {
            $this->markTestSkipped(
                'The engine run could not be resolved — set AI_LIFECYCLE_DIR to its debug directory.'
            );
        }

        $enriched = $result->reviews()->whereNotNull('bbox')->get();

        $this->assertNotEmpty($enriched, 'No review row picked up a bounding box.');

        $review = $enriched->first();
        $this->assertCount(4, $review->bbox);
        $this->assertNotEmpty($review->stages);
        $this->assertNotEmpty($review->final_decision);
        $this->assertNotNull($review->crop_id);

        // Crop images are filed on our own disk and served through the app.
        $this->actingAs($this->user)
            ->get("/reviews/{$result->id}/crops/{$review->id}")
            ->assertOk();
    }

    public function test_a_drawing_the_engine_rejects_fails_the_run_rather_than_inventing_results(): void
    {
        $this->skipUnlessEngineIsUp();

        // A PDF the engine cannot read anything from: the run must fail, not
        // fabricate a takeoff.
        $empty = UploadedFile::fake()->create('blank.pdf', 8, 'application/pdf');

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$empty]]);

        $aiJob = AiJob::latest('id')->firstOrFail();

        $this->assertSame(AiJob::STATUS_FAILED, $aiJob->status);
        $this->assertNotEmpty($aiJob->error_message);
        $this->assertSame('failed', $aiJob->project->fresh()->status);
        $this->assertDatabaseCount('ai_results', 0);
        $this->assertDatabaseCount('symbol_reviews', 0);
    }

    public function test_unsupported_files_are_rejected(): void
    {
        $this->actingAs($this->user)
            ->from('/ai-takeoff/upload')
            ->post('/ai-takeoff/upload', [
                'files' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')],
            ])
            ->assertSessionHasErrors('files.0');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_oversized_files_are_rejected(): void
    {
        $limitKb = config('takeoff.uploads.max_file_size_mb') * 1024;

        $this->actingAs($this->user)
            ->from('/ai-takeoff/upload')
            ->post('/ai-takeoff/upload', [
                'files' => [UploadedFile::fake()->create('huge.pdf', $limitKb + 1, 'application/pdf')],
            ])
            ->assertSessionHasErrors('files.0');
    }

    public function test_too_many_files_are_rejected(): void
    {
        $tooMany = config('takeoff.uploads.max_files') + 1;

        $files = [];
        for ($i = 0; $i < $tooMany; $i++) {
            $files[] = UploadedFile::fake()->create("sheet-{$i}.pdf", 10, 'application/pdf');
        }

        $this->actingAs($this->user)
            ->from('/ai-takeoff/upload')
            ->post('/ai-takeoff/upload', ['files' => $files])
            ->assertSessionHasErrors('files');
    }

    public function test_the_processing_screen_and_its_poll_report_the_runs_real_state(): void
    {
        $result = $this->ingestRealAnalysis($this->user);
        $project = $result->project;

        $this->actingAs($this->user)
            ->get("/processing/{$project->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Processing')
                ->where('run.status', AiJob::STATUS_SUCCEEDED)
                ->where('run.progress', 100)
                ->where('run.finished', true)
                ->where('run.reviewUrl', "/reviews/{$result->id}")
                ->has('run.pipelineStatus'));

        $this->actingAs($this->user)
            ->getJson("/processing/{$project->id}/status")
            ->assertOk()
            ->assertJson([
                'status' => AiJob::STATUS_SUCCEEDED,
                'finished' => true,
                'reviewUrl' => "/reviews/{$result->id}",
            ]);
    }

    /**
     * Cancelling removes the drawing it was raised against — row and file
     * both — so there is nothing left to resubmit against afterwards.
     */
    public function test_a_cancelled_run_removes_its_drawing_and_cannot_be_resubmitted(): void
    {
        $this->skipUnlessEngineIsUp();
        Queue::fake();

        $this->actingAs($this->user)->post('/ai-takeoff/upload', ['files' => [$this->fixtureUpload()]]);
        $project = Project::latest('id')->firstOrFail();
        $upload = Upload::where('project_id', $project->id)->sole();
        $disk = Storage::disk((string) config('takeoff.uploads.disk'));
        $this->assertTrue($disk->exists($upload->path));

        $this->actingAs($this->user)
            ->from("/processing/{$project->id}")
            ->post("/processing/{$project->id}/cancel")
            ->assertRedirect("/processing/{$project->id}");

        $this->assertSame('failed', $project->fresh()->status);
        $this->assertSame(AiJob::STATUS_CANCELLED, AiJob::latest('id')->firstOrFail()->status);

        // Gone — the row and the file it pointed at.
        $this->assertDatabaseMissing('uploads', ['id' => $upload->id]);
        $this->assertFalse($disk->exists($upload->path));

        $this->actingAs($this->user)
            ->from("/processing/{$project->id}")
            ->post("/processing/{$project->id}/restart")
            ->assertSessionHas('warning');

        // Nothing was queued a second time — there was no drawing left to run against.
        $this->assertSame(1, AiJob::where('project_id', $project->id)->count());
        Queue::assertPushed(ProcessTakeoffRun::class, 1);
    }

    public function test_opening_results_hands_over_to_the_review_workflow(): void
    {
        $result = $this->ingestRealAnalysis($this->user);

        $this->actingAs($this->user)
            ->get("/results/{$result->project_id}")
            ->assertRedirect("/reviews/{$result->id}");

        $this->actingAs($this->user)
            ->get('/results')
            ->assertRedirect("/reviews/{$result->id}");
    }

    public function test_a_takeoff_belonging_to_someone_else_is_off_limits(): void
    {
        $result = $this->ingestRealAnalysis($this->user);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get("/reviews/{$result->id}")->assertForbidden();
        $this->actingAs($stranger)->get("/processing/{$result->project_id}")->assertForbidden();
    }

    /** Artefacts are written to the real disk, so each test cleans up after itself. */
    private function deleteTestArtefacts(): void
    {
        $disk = Storage::disk(config('ai.storage.disk'));

        $disk->delete('uploads/sample-drawing.pdf');

        foreach (Project::withTrashed()->pluck('id') as $projectId) {
            $disk->deleteDirectory(config('ai.storage.directory')."/{$projectId}");
        }
    }
}
