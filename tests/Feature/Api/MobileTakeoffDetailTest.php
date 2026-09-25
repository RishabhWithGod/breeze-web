<?php

namespace Tests\Feature\Api;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\BoqLine;
use App\Models\Circuit;
use App\Models\DrawingSheet;
use App\Models\EquipmentItem;
use App\Models\FinalSymbol;
use App\Models\PanelSchedule;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\User;
use App\Models\WireSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The mobile AI Takeoff detail endpoint's read-only parity with web (sheets,
 * enriched symbol review fields, the config-driven processing checklist,
 * final takeoff data once signed off, approval history) and its one real
 * mutation — approve/reject/approve-remaining on a symbol review
 * (`Api\V1\SymbolReviewController`), mirroring web's own.
 */
class MobileTakeoffDetailTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'Project Manager']);
    }

    private function makeProject(User $owner, array $attributes = []): Project
    {
        return Project::create([
            'user_id' => $owner->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'processing',
            ...$attributes,
        ]);
    }

    private function makeResult(Project $project, User $owner, array $jobAttributes = [], array $resultAttributes = []): AiResult
    {
        $upload = $project->uploads()->create([
            'user_id' => $owner->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
        $job = AiJob::create([
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'user_id' => $owner->id,
            'status' => AiJob::STATUS_PROCESSING,
            'progress' => 40,
            'stage' => 'read',
            ...$jobAttributes,
        ]);

        return AiResult::create([
            'ai_job_id' => $job->id,
            'project_id' => $project->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
            ...$resultAttributes,
        ]);
    }

    // --- show(): enriched read data ------------------------------------------

    public function test_show_returns_sheets_and_processing_checklist(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        DrawingSheet::create(['project_id' => $project->id, 'code' => 'E-101', 'title' => 'Power Plan', 'page_count' => 2, 'position' => 0, 'scale' => "1/4\" = 1'", 'symbol_count' => 5]);
        $this->makeResult($project, $owner, ['progress' => 40, 'status' => AiJob::STATUS_PROCESSING, 'stage' => 'read']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'sheets' => ['*' => ['code', 'title', 'pageCount']],
                    'stages' => ['*' => ['id', 'label', 'description', 'status']],
                    'jobStatus', 'stageLabel', 'error', 'submittedAt', 'completedAt',
                    'modelVersion', 'overallConfidence', 'detectionCount', 'processingTime',
                    'reviewStatus', 'isFinalised', 'finalisedAt', 'pipelineStatus', 'warnings',
                    'final', 'history',
                ],
            ]);

        $this->assertSame('E-101', $response->json('data.sheets.0.code'));
        $this->assertSame(7, count($response->json('data.stages')));
        $this->assertFalse($response->json('data.isFinalised'));
        $this->assertNull($response->json('data.final'));
    }

    public function test_symbol_entries_carry_the_full_review_detail(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $result->reviews()->create([
            'project_id' => $project->id,
            'name' => 'Duplex Receptacle',
            'ai_name' => 'Duplex Receptacle',
            'ai_category' => 'known',
            'confidence' => 0.94,
            'status' => 'pending',
            'page' => 1,
            'reason' => 'legend match',
            'notes' => 'double-checked',
            'ai_count' => 3,
            'final_count' => 3,
            'occurrences' => [['bbox' => [0, 0, 1, 1]], ['bbox' => [1, 1, 2, 2]]],
            'is_known' => true,
            'source_template' => true,
            'source_vision' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk();

        $symbol = $response->json('data.symbols.0');
        $this->assertSame('Duplex Receptacle', $symbol['label']);
        $this->assertSame('legend match', $symbol['reason']);
        $this->assertSame('double-checked', $symbol['notes']);
        $this->assertSame(3, $symbol['aiCount']);
        $this->assertSame(3, $symbol['finalCount']);
        $this->assertSame(2, $symbol['occurrencesCount']);
        $this->assertTrue($symbol['isKnown']);
        $this->assertSame(['Template', 'Vision'], $symbol['sources']);
    }

    public function test_final_data_is_null_until_finalised_then_carries_every_table(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner, ['status' => 'completed']);
        $result = $this->makeResult($project, $owner, ['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100], [
            'review_status' => AiResult::REVIEW_FINALISED,
            'finalised_at' => now(),
            'final_payload' => ['metadata' => ['approved' => 5, 'rejected' => 1, 'modified' => 2, 'ai_item_total' => 8], 'boq' => ['lines' => [['x' => 1]], 'materials' => [], 'totals' => ['labor_hours' => 4.5, 'material_cost' => 120]]],
        ]);
        FinalSymbol::create(['ai_result_id' => $result->id, 'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'count' => 5, 'confidence' => 0.9, 'source_template' => true]);
        BoqLine::create(['ai_result_id' => $result->id, 'item' => 'Receptacle', 'description' => 'Duplex', 'quantity' => 5, 'unit' => 'ea', 'unit_price' => 2.5, 'subtotal' => 12.5]);
        WireSize::create(['ai_result_id' => $result->id, 'page' => 1, 'size' => '12 AWG', 'context' => 'Branch circuits', 'count' => 10]);
        PanelSchedule::create(['ai_result_id' => $result->id, 'page' => 1, 'panel_name' => 'Panel A', 'rows' => [['circuit' => '1', 'load' => 'Lighting']], 'raw_headers' => ['Circuit', 'Load']]);
        EquipmentItem::create(['ai_result_id' => $result->id, 'page' => 1, 'tag' => 'P-1', 'description' => 'Panel', 'rating' => '200A', 'quantity' => 1]);
        Circuit::create(['ai_result_id' => $result->id, 'page' => 1, 'number' => '1', 'description' => 'Lighting', 'breaker' => '20A', 'panel' => 'Panel A']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk();

        $this->assertTrue($response->json('data.isFinalised'));
        $final = $response->json('data.final');
        $this->assertNotNull($final);
        $this->assertSame(5, $final['totals']['items']);
        $this->assertSame('Duplex Receptacle', $final['finalSymbols'][0]['name']);
        $this->assertSame(['x' => 1], $final['boq']['lines'][0]);
        $this->assertSame('Receptacle', $final['engineBoq'][0]['item']);
        $this->assertSame('12 AWG', $final['wireSizes'][0]['size']);
        $this->assertSame('Panel A', $final['panelSchedules'][0]['panelName']);
        $this->assertSame('P-1', $final['equipment'][0]['tag']);
        $this->assertSame('1', $final['circuits'][0]['number']);
    }

    public function test_drawing_facts_and_engine_data_are_available_before_finalisation(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], [
            'page_count' => 7,
            'ai_estimate' => ['subtotal' => 2000.0, 'tax' => 355.20, 'tax_rate' => 0.0725, 'grand_total' => 2355.20, 'currency' => 'USD', 'line_count' => 15],
        ]);
        BoqLine::create(['ai_result_id' => $result->id, 'item' => 'Receptacle', 'description' => 'Duplex', 'quantity' => 5, 'unit' => 'ea', 'unit_price' => 2.5, 'subtotal' => 12.5]);
        WireSize::create(['ai_result_id' => $result->id, 'page' => 1, 'size' => '12 AWG', 'context' => 'Branch circuits', 'count' => 10]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk();

        // Not finalised — the reviewed/final section is still null.
        $this->assertFalse($response->json('data.isFinalised'));
        $this->assertNull($response->json('data.final'));

        // But the engine's own automatic read of the drawing is already here.
        $this->assertSame(7, $response->json('data.pageCount'));
        $this->assertSame(1024, $response->json('data.fileSizeBytes'));
        $this->assertSame('PDF', $response->json('data.fileFormat'));
        $this->assertSame('Receptacle', $response->json('data.engineBoq.0.item'));
        $this->assertSame('12 AWG', $response->json('data.wireSizes.0.size'));
        $this->assertEquals(2355.20, $response->json('data.estimateTotals.grandTotal'));
        $this->assertSame(15, $response->json('data.estimateTotals.lineCount'));
    }

    public function test_estimate_totals_is_null_when_the_engine_priced_nothing(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $this->makeResult($project, $owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk();

        $this->assertNull($response->json('data.estimateTotals'));
        $this->assertSame([], $response->json('data.engineBoq'));
        $this->assertSame([], $response->json('data.wireSizes'));
    }

    // --- pdf(): the drawing file itself --------------------------------------

    public function test_pdf_streams_the_uploaded_drawing_inline(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $upload = $project->uploads()->create([
            'user_id' => $owner->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
            'path' => 'takeoffs/'.$project->id.'/E-101.pdf',
        ]);
        Storage::disk('local')->put($upload->path, '%PDF-1.4 fake contents');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/takeoffs/{$project->id}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_is_forbidden_for_a_non_owner(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $other = $this->owner();
        $project = $this->makeProject($owner);
        $upload = $project->uploads()->create([
            'user_id' => $owner->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
            'path' => 'takeoffs/'.$project->id.'/E-101.pdf',
        ]);
        Storage::disk('local')->put($upload->path, '%PDF-1.4 fake contents');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->get("/api/v1/takeoffs/{$project->id}/pdf")
            ->assertForbidden();
    }

    public function test_pdf_404s_when_no_file_is_on_disk(): void
    {
        Storage::fake('local');
        $owner = $this->owner();
        $project = $this->makeProject($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->get("/api/v1/takeoffs/{$project->id}/pdf")
            ->assertNotFound();
    }

    public function test_show_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $project = $this->makeProject($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertForbidden();
    }

    // --- approve / reject / approve-remaining --------------------------------

    public function test_a_manager_can_approve_a_symbol(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $response->json('data.status'));
        $fresh = $review->fresh();
        $this->assertSame(SymbolReview::STATUS_APPROVED, $fresh->status);
        $this->assertSame($owner->id, $fresh->reviewed_by);
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertDatabaseHas('approval_histories', ['symbol_review_id' => $review->id, 'action' => 'approved']);
    }

    public function test_a_manager_can_reject_a_symbol_with_a_reason(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/reject", ['reason' => 'Not on this sheet'])
            ->assertOk();

        $fresh = $review->fresh();
        $this->assertSame(SymbolReview::STATUS_REJECTED, $fresh->status);
        $this->assertSame('Not on this sheet', $fresh->notes);
    }

    public function test_approve_is_forbidden_once_the_takeoff_is_finalised(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['review_status' => AiResult::REVIEW_FINALISED, 'finalised_at' => now()]);
        $review = $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/approve")
            ->assertForbidden();
    }

    public function test_a_non_owner_cannot_approve_a_symbol(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/approve")
            ->assertForbidden();
    }

    public function test_approve_remaining_approves_every_pending_symbol(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $result->reviews()->create(['project_id' => $project->id, 'name' => 'A', 'ai_name' => 'A', 'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9]);
        $result->reviews()->create(['project_id' => $project->id, 'name' => 'B', 'ai_name' => 'B', 'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9]);
        $result->reviews()->create(['project_id' => $project->id, 'name' => 'C', 'ai_name' => 'C', 'status' => SymbolReview::STATUS_REJECTED, 'confidence' => 0.9]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/approve-remaining")
            ->assertOk();

        $this->assertSame(2, $response->json('data.approvedCount'));
        $this->assertSame(0, $result->reviews()->where('status', SymbolReview::STATUS_PENDING)->count());
    }
}
