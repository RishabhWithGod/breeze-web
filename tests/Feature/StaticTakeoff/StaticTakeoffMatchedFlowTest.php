<?php

namespace Tests\Feature\StaticTakeoff;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\Project;
use App\Models\StaticTakeoffDataset;
use App\Models\Team;
use App\Models\Upload;
use App\Models\User;
use App\Services\Ai\AiApiException;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drives a takeoff run through the isolated static engine, then through the
 * exact same review/finalise/estimate/job endpoints the dynamic flow uses
 * (see `Tests\Feature\Api\MobileTakeoffFlowTest`) — proving the static
 * dataset only replaces the analyse() call, nothing downstream.
 */
class StaticTakeoffMatchedFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PDF_BYTES = "%PDF-1.4\nfixture drawing\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();

        config(['static_takeoff.enabled' => true, 'static_takeoff.fallback_to_dynamic' => false]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'Project Manager']);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** @return array{0: Project, 1: Upload} */
    private function projectWithStoredDrawing(User $owner, string $bytes = self::PDF_BYTES): array
    {
        $project = Project::create([
            'user_id' => $owner->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'processing',
        ]);

        $path = 'uploads/drawing.pdf';
        Storage::disk('local')->put($path, $bytes);

        $upload = $project->uploads()->create([
            'user_id' => $owner->id,
            'name' => 'drawing.pdf',
            'format' => 'PDF',
            'size_bytes' => strlen($bytes),
            'path' => $path,
            'status' => 'processing',
        ]);

        $project->update(['selected_upload_id' => $upload->id]);

        return [$project, $upload];
    }

    private function registerDataset(string $bytes = self::PDF_BYTES): StaticTakeoffDataset
    {
        return StaticTakeoffDataset::create([
            'name' => 'Fixture Panel',
            'file_hash' => hash('sha256', $bytes),
            'original_filename' => 'drawing.pdf',
            'file_size' => strlen($bytes),
            'mime_type' => 'application/pdf',
            'takeoff_payload' => [
                'project_name' => 'Fixture Panel',
                'run_id' => 'static-fixture-run',
                'pages' => 1,
                'symbols' => [[
                    'name' => 'Duplex Receptacle',
                    'count' => 5,
                    'confidence' => 0.92,
                    'sources' => ['template'],
                    'evidence' => ['template'],
                    'detections' => array_map(fn (int $i) => [
                        'id' => "occ-{$i}",
                        'bbox' => ['x' => 10 * $i, 'y' => 10, 'w' => 8, 'h' => 8],
                        'page' => 1,
                        'confidence' => 0.9,
                    ], range(1, 5)),
                ]],
                'known_symbols' => [['name' => 'Duplex Receptacle']],
                'unknown_symbols' => [],
                'rejected_symbols' => [],
                'needs_review' => [],
                'panel_schedules' => [],
                'equipment' => [],
                'wire_sizes' => [],
                'circuits' => [],
                'boq' => [[
                    'item' => 'Duplex Receptacle',
                    'description' => 'Duplex Receptacle',
                    'quantity' => 5,
                    'unit' => 'ea',
                    'unit_price' => 5,
                    'subtotal' => 25,
                ]],
                'estimate' => [
                    'subtotal' => 25,
                    'tax_rate' => 0.0825,
                    'tax' => 2.06,
                    'grand_total' => 27.06,
                    'currency' => 'USD',
                    'line_count' => 1,
                ],
                'warnings' => [],
                'processing_time' => 1.4,
                'pipeline_status' => ['detect' => 'green'],
                // Static-mode-only extension: carried onto ai_results.page_sizes by
                // App\Services\StaticTakeoff\Listeners\AttachStaticPageSizes.
                'page_sizes' => ['1' => ['width' => 1200, 'height' => 900]],
            ],
            'is_active' => true,
        ]);
    }

    /** Mirrors `ProcessTakeoffRun::handle()`'s try/catch, without the queue. */
    private function runAnalysis(AiJob $aiJob): ?AiResult
    {
        $orchestrator = app(TakeoffOrchestrator::class);

        try {
            return $orchestrator->analyse($aiJob);
        } catch (AiApiException $e) {
            $orchestrator->fail($aiJob, $e->getMessage());

            return null;
        }
    }

    public function test_a_matched_pdf_is_answered_from_the_static_dataset_without_any_dynamic_http_call(): void
    {
        Storage::fake('local');
        Http::fake();
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);
        $this->registerDataset();

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $result = $this->runAnalysis($aiJob);

        Http::assertNothingSent();

        $this->assertNotNull($result);
        $this->assertSame('Fixture Panel', $result->project_name);
        $this->assertSame(AiJob::STATUS_SUCCEEDED, $aiJob->fresh()->status);

        // Correct PDF stored.
        $this->assertTrue(Storage::disk('local')->exists($upload->path));
        $this->assertSame(self::PDF_BYTES, Storage::disk('local')->get($upload->path));

        // Correct symbols/occurrences loaded.
        $review = $result->reviews()->firstOrFail();
        $this->assertSame('Duplex Receptacle', $review->name);
        $this->assertSame(5, $review->final_count);
        $this->assertCount(5, $review->occurrences);

        // Correct page dimensions loaded (via the static page_sizes listener).
        $this->assertEquals(
            ['width' => 1200.0, 'height' => 900.0],
            $result->fresh()->page_sizes[1],
        );
    }

    public function test_review_finalise_estimate_and_job_creation_work_unchanged_on_a_static_result(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);
        $this->registerDataset();
        PriceBookItem::create([
            'user_id' => $owner->id,
            'match_key' => PriceBookLine::keyFor('Duplex Receptacle'),
            'unit' => 'ea',
            'description' => 'Duplex Receptacle',
            'unit_material_cost' => 5.0,
            'unit_manhours' => 0.5,
            'sample_count' => 1,
        ]);

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $result = $this->runAnalysis($aiJob);
        $review = $result->reviews()->firstOrFail();
        $token = 'Bearer '.$this->tokenFor($owner);

        // Review actions still work.
        $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/count", ['count' => 4])
            ->assertOk();
        $this->assertSame(4, $review->fresh()->final_count);

        // Finalise still works.
        $finalise = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();
        $this->assertTrue($result->fresh()->isFinalised());

        // Estimate comes from the stored static quantities, through the
        // existing EstimateBuilder pipeline (unmodified).
        $estimateId = $finalise->json('data.estimateId');
        $this->assertNotNull($estimateId);
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimateId,
            'quantity' => 4,
        ]);

        // Job creation still works.
        $team = Team::create(['name' => 'Crew A']);
        $job = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/job", [
                'team_id' => $team->id,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-15',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('work_jobs', [
            'id' => $job->json('data.jobId'),
            'ai_result_id' => $result->id,
            'team_id' => $team->id,
        ]);
    }

    public function test_the_estimate_is_priced_from_the_static_dataset_not_the_price_book(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);
        $this->registerDataset();
        // Deliberately no PriceBookItem/ProjectRateBook seeded anywhere in
        // this test — proves the price below came from the static dataset's
        // own boq (unit_price: 5, tax_rate: 0), not a company rate, which
        // would otherwise price an unmatched line at zero.

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $this->runAnalysis($aiJob);
        $token = 'Bearer '.$this->tokenFor($owner);

        $finalise = $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $estimateId = $finalise->json('data.estimateId');
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimateId,
            'pricing_source' => 'vendor-rate-list',
            'quantity' => 5,
            'unit_cost' => 5,
        ]);

        // 5 units * $5/ea = $25 subtotal; tax_pct comes from the dataset's
        // own `estimate.tax_rate` (8.25%, not the app's configured default
        // or a company rate); markup_pct stays 0 since this fixture sets no
        // `estimate.markup_rate` — nothing from the project's own rate
        // book/price book/config is added on top either way.
        $estimate = \App\Models\Estimate::find($estimateId);
        $this->assertSame(25.0, (float) $estimate->subtotal);
        $this->assertSame(8.25, (float) $estimate->tax_pct);
        $this->assertSame(0.0, (float) $estimate->markup_pct);
        $this->assertSame(27.06, (float) $estimate->grand_total);
    }

    public function test_labor_is_priced_separately_at_the_datasets_own_composite_rate(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);

        // A different labor rate ($61.71/hr) than any company default would
        // ever plausibly be, and deliberately no PriceBookItem/ProjectRateBook
        // seeded — proves the labor line below is priced at the dataset's own
        // rate, not a company one.
        StaticTakeoffDataset::create([
            'name' => 'Labor Fixture',
            'file_hash' => hash('sha256', self::PDF_BYTES),
            'original_filename' => 'drawing.pdf',
            'file_size' => strlen(self::PDF_BYTES),
            'mime_type' => 'application/pdf',
            'takeoff_payload' => [
                'project_name' => 'Labor Fixture',
                'run_id' => 'static-labor-run',
                'pages' => 1,
                'symbols' => [[
                    'name' => 'Conduit Run',
                    'count' => 10,
                    'confidence' => 1,
                    'sources' => [],
                    'evidence' => ['ESTIMATE'],
                    'detections' => [],
                ]],
                'known_symbols' => [['name' => 'Conduit Run']],
                'unknown_symbols' => [],
                'rejected_symbols' => [],
                'needs_review' => [],
                'panel_schedules' => [],
                'equipment' => [],
                'wire_sizes' => [],
                'circuits' => [],
                'boq' => [[
                    'item' => 'Conduit Run',
                    'description' => 'Conduit Run',
                    'quantity' => 10,
                    'unit' => 'ea',
                    'unit_price' => 2,
                    'subtotal' => 20,
                    'unit_material_cost' => 2,
                    'unit_manhours' => 0.5,
                ]],
                'estimate' => [
                    'subtotal' => 20, 'tax_rate' => 0, 'tax' => 0,
                    'grand_total' => 20, 'currency' => 'USD', 'line_count' => 1,
                ],
                'warnings' => [],
                'processing_time' => 1.0,
                'pipeline_status' => [],
                '_labor_rate' => 61.71,
            ],
            'is_active' => true,
        ]);

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $this->runAnalysis($aiJob);
        $token = 'Bearer '.$this->tokenFor($owner);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $estimateId = \App\Models\AiResult::first()->estimate_id;

        // Material line: 10 * $2 = $20.
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimateId,
            'category' => 'material',
            'quantity' => 10,
            'unit_cost' => 2,
        ]);

        // Labor line: 0.5 hr/unit * 10 units = 5 hrs, at the dataset's own
        // $61.71/hr — not any company rate book/price book default.
        $this->assertDatabaseHas('estimate_items', [
            'estimate_id' => $estimateId,
            'category' => 'labor',
            'quantity' => 5,
            'unit_cost' => 61.71,
        ]);

        $estimate = \App\Models\Estimate::find($estimateId);
        // $20 material + (5 hrs * $61.71) = $328.55.
        $this->assertSame(328.55, (float) $estimate->subtotal);
    }

    public function test_a_genuinely_zero_tax_rate_does_not_fall_back_to_a_company_default(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);

        // `EstimateBuilder::taxPercent()` treats a tax_rate of exactly 0 the
        // same as "not set" and falls back to the project's rate book/price
        // book/config default (8.25% with nothing else seeded — see
        // config('ai.estimating.tax_pct')) — proving the listener corrects
        // this rather than letting a real company tax rate leak in.
        StaticTakeoffDataset::create([
            'name' => 'Zero Tax Fixture',
            'file_hash' => hash('sha256', self::PDF_BYTES),
            'original_filename' => 'drawing.pdf',
            'file_size' => strlen(self::PDF_BYTES),
            'mime_type' => 'application/pdf',
            'takeoff_payload' => [
                'project_name' => 'Zero Tax Fixture',
                'run_id' => 'static-zero-tax-run',
                'pages' => 1,
                'symbols' => [[
                    'name' => 'Fixture Item',
                    'count' => 4,
                    'confidence' => 1,
                    'sources' => [],
                    'evidence' => ['ESTIMATE'],
                    'detections' => [],
                ]],
                'known_symbols' => [['name' => 'Fixture Item']],
                'unknown_symbols' => [],
                'rejected_symbols' => [],
                'needs_review' => [],
                'panel_schedules' => [],
                'equipment' => [],
                'wire_sizes' => [],
                'circuits' => [],
                'boq' => [[
                    'item' => 'Fixture Item',
                    'description' => 'Fixture Item',
                    'quantity' => 4,
                    'unit' => 'ea',
                    'unit_price' => 10,
                    'subtotal' => 40,
                    'unit_material_cost' => 10,
                    'unit_manhours' => 0,
                ]],
                'estimate' => [
                    'subtotal' => 40, 'tax_rate' => 0, 'tax' => 0,
                    'grand_total' => 40, 'currency' => 'USD', 'line_count' => 1,
                ],
                'warnings' => [],
                'processing_time' => 1.0,
                'pipeline_status' => [],
                '_labor_rate' => 48,
            ],
            'is_active' => true,
        ]);

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $this->runAnalysis($aiJob);
        $token = 'Bearer '.$this->tokenFor($owner);

        $this->withHeader('Authorization', $token)
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $estimate = \App\Models\Estimate::find(\App\Models\AiResult::first()->estimate_id);
        $this->assertSame(0.0, (float) $estimate->tax_pct);
        $this->assertSame(40.0, (float) $estimate->grand_total);
    }

    public function test_a_static_upload_goes_through_the_normal_queue_like_any_other_run(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $project = Project::create([
            'user_id' => $owner->id, 'name' => 'Data Hall', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $this->registerDataset();
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('drawing.pdf', self::PDF_BYTES);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/upload", [
                'project_id' => $project->id,
                'files' => [$file],
            ])
            ->assertCreated();

        // Static mode dispatches `ProcessTakeoffRun` exactly like the
        // dynamic flow — a real queue worker still picks it up. This test's
        // env sets QUEUE_CONNECTION=sync (and STATIC_TAKEOFF_SIMULATED_SECONDS=0,
        // see phpunit.xml) so it runs immediately here without a real wait;
        // in normal use, `StaticTakeoffEngine` holds a matched run for
        // `static_takeoff.simulated_processing_seconds` so the processing
        // screen still shows it in progress rather than skipping straight to done.
        $this->assertSame(AiJob::STATUS_SUCCEEDED, AiJob::latest('id')->first()->status);
        $this->assertSame(1, AiResult::latest('id')->first()->reviews()->count());
    }

    public function test_the_engine_overlap_lock_is_skipped_in_static_mode(): void
    {
        // Nothing external to take turns on when answering from a dataset —
        // and on the `sync` connection a job that can't acquire the lock has
        // nowhere to be released back to (see `ProcessTakeoffRun::middleware()`).
        $this->assertSame([], (new \App\Jobs\ProcessTakeoffRun(1))->middleware());

        config(['static_takeoff.enabled' => false]);
        $this->assertNotSame([], (new \App\Jobs\ProcessTakeoffRun(1))->middleware());
    }

    public function test_an_unmatched_pdf_fails_cleanly_without_fabricating_data(): void
    {
        Storage::fake('local');
        Http::fake();
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner, 'no dataset registered for this content');
        // No dataset registered for this file's hash.

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $this->runAnalysis($aiJob);

        Http::assertNothingSent();
        $this->assertSame(AiJob::STATUS_FAILED, $aiJob->fresh()->status);
        // Worded for the reviewer, not the mechanism — never names "static".
        $this->assertSame(
            "This drawing hasn't been synced yet. Please upload a drawing that has already been synced, or contact your administrator to sync it.",
            $aiJob->fresh()->error_message,
        );
        $this->assertSame('failed', $project->fresh()->status);
        $this->assertDatabaseCount('ai_results', 0);
        $this->assertDatabaseCount('symbol_reviews', 0);
    }

    public function test_disabling_the_flag_restores_the_dynamic_engine(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        [$project, $upload] = $this->projectWithStoredDrawing($owner);
        $this->registerDataset();

        config(['static_takeoff.enabled' => false]);

        Http::fake(['*' => Http::response(['pages' => 0], 500)]);

        $aiJob = app(TakeoffOrchestrator::class)->open($project, $upload, $owner);
        $this->runAnalysis($aiJob);

        // The dynamic client is expected to fail against the fake HTTP response
        // above — the point being verified is that it was called at all.
        Http::assertSentCount(1);
    }
}
