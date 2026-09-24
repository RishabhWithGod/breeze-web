<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Project → Upload → Processing → Review → Finalise → Estimate → Job →
 * Task flow, as the mobile app drives it — every new endpoint this pass
 * added on top of the existing read-only `TakeoffController::show()` and
 * approve/reject. Each action calls the exact same service classes web's
 * own controllers do (`TakeoffOrchestrator`, `CompleteReview`,
 * `EstimateBuilder`, `JobFactory`) — no second implementation of any
 * business rule.
 */
class MobileTakeoffFlowTest extends TestCase
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

    private function makeClient(User $owner): Client
    {
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Harborview LLC']);
        ClientAddress::create([
            'client_id' => $client->id,
            'label' => 'Main site',
            'address' => '1 Harbor Way',
            'is_primary' => true,
            'position' => 0,
        ]);

        return $client;
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

    // --- Project::store -------------------------------------------------

    public function test_a_manager_can_open_a_project(): void
    {
        $owner = $this->owner();
        $client = $this->makeClient($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/projects', ['name' => 'New Warehouse', 'client_id' => $client->id])
            ->assertCreated();

        $this->assertSame('New Warehouse', $response->json('data.name'));
        $this->assertDatabaseHas('projects', [
            'name' => 'New Warehouse',
            'client_id' => $client->id,
            'user_id' => $owner->id,
            'client' => 'Harborview LLC',
            'status' => 'draft',
        ]);
    }

    public function test_project_store_requires_a_name_and_an_owned_client(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $otherClient = $this->makeClient($other);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/projects', ['name' => 'X', 'client_id' => $otherClient->id])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/projects', ['client_id' => $this->makeClient($owner)->id])
            ->assertStatus(422);
    }

    // --- Upload::store ----------------------------------------------------

    public function test_uploading_a_drawing_opens_a_run(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = $this->owner();
        $project = $this->makeProject($owner, ['status' => 'draft']);
        $file = UploadedFile::fake()->create('drawing.pdf', 100, 'application/pdf');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/upload", [
                'project_id' => $project->id,
                'files' => [$file],
            ])
            ->assertCreated();

        $this->assertSame('queued', $response->json('data.status'));
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'processing', 'drawing_name' => 'drawing.pdf']);
        $this->assertDatabaseHas('uploads', ['project_id' => $project->id, 'format' => 'PDF']);
        Queue::assertPushed(RenderDrawingPreviews::class);
        Queue::assertPushed(ProcessTakeoffRun::class);
    }

    public function test_upload_rejects_another_managers_project(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = $this->owner();
        $other = $this->owner();
        $project = $this->makeProject($other, ['status' => 'draft']);
        $file = UploadedFile::fake()->create('drawing.pdf', 100, 'application/pdf');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/upload", ['project_id' => $project->id, 'files' => [$file]])
            ->assertStatus(422);
    }

    // --- Processing::status/retry/cancel -----------------------------------

    public function test_processing_status_reports_the_run_state(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, ['progress' => 55, 'stage' => 'analysing']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}/processing")
            ->assertOk();

        $this->assertSame(55, $response->json('data.progress'));
        $this->assertSame('analysing', $response->json('data.stage'));
        $this->assertFalse($response->json('data.finished'));
        unset($result);
    }

    public function test_processing_retry_requeues_an_open_run(): void
    {
        Queue::fake();
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $this->makeResult($project, $owner, ['status' => AiJob::STATUS_QUEUED, 'progress' => 0, 'stage' => 'queued']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/processing/retry")
            ->assertOk();

        Queue::assertPushed(ProcessTakeoffRun::class);
    }

    public function test_processing_retry_refuses_a_finished_run(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $this->makeResult($project, $owner, ['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/processing/retry")
            ->assertStatus(422);
    }

    public function test_processing_restart_resubmits_a_failed_run(): void
    {
        Storage::fake('local');
        Queue::fake();
        $owner = $this->owner();
        $project = $this->makeProject($owner, ['status' => 'failed']);
        $upload = $project->uploads()->create([
            'user_id' => $owner->id,
            'name' => 'drawing.pdf',
            'format' => 'PDF',
            'size_bytes' => 17,
            'status' => 'failed',
            'path' => 'uploads/drawing.pdf',
        ]);
        Storage::disk('local')->put($upload->path, 'fake pdf contents');
        $project->update(['selected_upload_id' => $upload->id]);
        $this->makeResult($project, $owner, ['status' => AiJob::STATUS_FAILED, 'progress' => 35, 'error_message' => 'engine unreachable']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/processing/restart")
            ->assertOk();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'processing']);
        Queue::assertPushed(RenderDrawingPreviews::class);
        Queue::assertPushed(ProcessTakeoffRun::class);
    }

    public function test_processing_restart_refuses_without_a_drawing(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner, ['status' => 'failed']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/processing/restart")
            ->assertStatus(422);
    }

    public function test_processing_cancel_abandons_an_open_run(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $this->makeResult($project, $owner, ['status' => AiJob::STATUS_PROCESSING, 'progress' => 40]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/processing/cancel")
            ->assertOk();

        $this->assertDatabaseHas('ai_jobs', ['project_id' => $project->id, 'status' => AiJob::STATUS_CANCELLED]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'failed']);
    }

    // --- Overlay / page ------------------------------------------------------

    public function test_overlay_returns_bbox_and_page_dimensions(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['page_sizes' => [1 => ['width' => 800.0, 'height' => 600.0]]]);
        $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_APPROVED, 'confidence' => 0.9, 'page' => 1,
            'bbox' => [10, 10, 40, 40], 'final_count' => 1,
            'occurrences' => [['key' => 'occ_1', 'bbox' => [10, 10, 40, 40], 'page' => 1, 'status' => 'approved']],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}/overlay")
            ->assertOk();

        $this->assertSame('Duplex Receptacle', $response->json('data.symbols.0.name'));
        $this->assertSame([10, 10, 40, 40], $response->json('data.symbols.0.bbox'));
        $this->assertEquals(['width' => 800.0, 'height' => 600.0], $response->json('data.pageDimensions.1'));
    }

    // --- Symbol review: extended actions -------------------------------------

    private function makeReview(AiResult $result, Project $project, array $attributes = []): SymbolReview
    {
        return $result->reviews()->create([
            'project_id' => $project->id, 'name' => 'Duplex Receptacle', 'ai_name' => 'Duplex Receptacle',
            'status' => SymbolReview::STATUS_PENDING, 'confidence' => 0.9, 'ai_count' => 3, 'final_count' => 3,
            ...$attributes,
        ]);
    }

    public function test_reset_returns_a_decided_review_to_pending(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, ['status' => SymbolReview::STATUS_APPROVED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/reset")
            ->assertOk();

        $this->assertSame(SymbolReview::STATUS_PENDING, $review->fresh()->status);
    }

    public function test_count_accepts_an_absolute_value_and_a_step(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/count", ['count' => 8])
            ->assertOk()
            ->assertJsonPath('data.finalCount', 8);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/count", ['step' => -3])
            ->assertOk()
            ->assertJsonPath('data.finalCount', 5);
    }

    public function test_rename_changes_the_name_used_in_the_final_json(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/rename", ['name' => 'GFCI Receptacle'])
            ->assertOk();

        $this->assertSame('GFCI Receptacle', $review->fresh()->name);
        $this->assertDatabaseHas('approval_histories', ['symbol_review_id' => $review->id, 'action' => 'renamed']);
    }

    public function test_note_saves_and_clears(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/note", ['notes' => 'Double check panel A'])
            ->assertOk();
        $this->assertSame('Double check panel A', $review->fresh()->notes);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/note", [])
            ->assertOk();
        $this->assertNull($review->fresh()->notes);
    }

    public function test_split_creates_a_sibling_with_the_split_off_count(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, ['final_count' => 10]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/split", ['name' => 'Weatherproof Receptacle', 'count' => 4])
            ->assertOk();

        $this->assertSame(6, $review->fresh()->final_count);
        $childId = $response->json('data.childId');
        $this->assertDatabaseHas('symbol_reviews', ['id' => $childId, 'final_count' => 4, 'split_from_id' => $review->id]);
    }

    public function test_split_refuses_a_review_counted_as_one(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, ['final_count' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/split", ['name' => 'X', 'count' => 1])
            ->assertStatus(422);
    }

    public function test_merge_combines_sources_into_the_target(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $target = $this->makeReview($result, $project, ['name' => 'A', 'final_count' => 3]);
        $source = $this->makeReview($result, $project, ['name' => 'B', 'final_count' => 2]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/merge", ['ids' => [$target->id, $source->id], 'target_id' => $target->id, 'name' => 'Merged'])
            ->assertOk()
            ->assertJsonPath('data.finalCount', 5);

        $this->assertSame($target->id, $source->fresh()->merged_into_id);
        $this->assertSame('Merged', $target->fresh()->name);
    }

    public function test_occurrence_toggle_steps_the_count_by_one(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, [
            'final_count' => 1,
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved']],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1/toggle")
            ->assertOk();

        $this->assertSame(0, $response->json('data.finalCount'));
        $this->assertSame(SymbolReview::STATUS_REJECTED, $response->json('data.status'));
    }

    public function test_move_occurrence_requires_confirmed_page_dimensions(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, [
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved']],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1/move", ['bbox' => [5, 5, 10, 10]])
            ->assertStatus(422);
    }

    public function test_move_occurrence_clamps_to_the_page_and_preserves_original_bbox(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['page_sizes' => [1 => ['width' => 100.0, 'height' => 100.0]]]);
        $review = $this->makeReview($result, $project, [
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved']],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1/move", ['bbox' => [95, 95, 20, 20]])
            ->assertOk();

        $this->assertEquals([80.0, 80.0, 20.0, 20.0], $response->json('data.bbox'));
        $occ = $review->fresh()->occurrences[0];
        $this->assertSame([0, 0, 10, 10], $occ['original_bbox']);
    }

    public function test_duplicate_occurrence_adds_a_new_one_and_increments_the_count(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, [
            'final_count' => 1,
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved']],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1/duplicate")
            ->assertOk();

        $this->assertSame(2, $response->json('data.finalCount'));
        $this->assertCount(2, $review->fresh()->occurrences);
    }

    public function test_delete_occurrence_refuses_an_ai_origin_occurrence(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, [
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved', 'origin' => 'ai']],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1")
            ->assertForbidden();
    }

    public function test_delete_occurrence_removes_a_manually_placed_one(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project, [
            'final_count' => 1,
            'occurrences' => [['key' => 'occ_1', 'bbox' => [0, 0, 10, 10], 'page' => 1, 'status' => 'approved', 'origin' => 'manual']],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/occurrences/occ_1")
            ->assertOk()
            ->assertJsonPath('data.finalCount', 0);
    }

    public function test_manual_add_creates_a_new_reviewable_symbol(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['page_count' => 3]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/manual-add", [
                'page' => 1, 'name' => 'Smoke Detector', 'bbox' => [10, 10, 20, 20],
            ])
            ->assertCreated();

        $this->assertSame(1, $response->json('data.finalCount'));
        $this->assertDatabaseHas('symbol_reviews', ['project_id' => $project->id, 'name' => 'Smoke Detector', 'origin' => SymbolReview::ORIGIN_MANUAL]);
    }

    public function test_bulk_sets_a_selection_to_the_given_status(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $a = $this->makeReview($result, $project, ['name' => 'A']);
        $b = $this->makeReview($result, $project, ['name' => 'B']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/bulk", ['ids' => [$a->id, $b->id], 'action' => 'reject'])
            ->assertOk()
            ->assertJsonPath('data.count', 2);

        $this->assertSame(SymbolReview::STATUS_REJECTED, $a->fresh()->status);
        $this->assertSame(SymbolReview::STATUS_REJECTED, $b->fresh()->status);
    }

    public function test_undo_reverses_the_most_recent_reversible_change(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $review = $this->makeReview($result, $project);
        $token = $this->tokenFor($owner);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/{$review->id}/approve")
            ->assertOk();
        $this->assertSame(SymbolReview::STATUS_APPROVED, $review->fresh()->status);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/undo")
            ->assertOk();

        $this->assertSame(SymbolReview::STATUS_PENDING, $review->fresh()->status);
    }

    public function test_undo_reports_nothing_to_undo_on_a_clean_run(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $this->makeResult($project, $owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/symbols/undo")
            ->assertStatus(422);
    }

    // --- Finalise / reopen / storeJob ----------------------------------------

    public function test_finalise_builds_the_final_json_and_raises_an_estimate(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, ['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100]);
        $this->makeReview($result, $project, ['status' => SymbolReview::STATUS_APPROVED, 'final_count' => 5]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $this->assertTrue($result->fresh()->isFinalised());
        $this->assertNotNull($response->json('data.estimateId'));
        $this->assertDatabaseHas('estimates', ['id' => $response->json('data.estimateId'), 'ai_result_id' => $result->id]);
    }

    public function test_finalise_is_idempotent(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['review_status' => AiResult::REVIEW_FINALISED, 'finalised_at' => now(), 'estimate_id' => null]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk()
            ->assertJsonPath('message', 'This review was already signed off.');
    }

    public function test_finalise_refuses_when_nothing_was_approved(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner);
        $this->makeReview($result, $project, ['status' => SymbolReview::STATUS_REJECTED]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertStatus(422);
    }

    public function test_reopen_returns_a_finalised_takeoff_to_review(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['review_status' => AiResult::REVIEW_FINALISED, 'finalised_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/reopen")
            ->assertOk();

        $this->assertSame(AiResult::REVIEW_IN_PROGRESS, $result->fresh()->review_status);
        $this->assertNull($result->fresh()->finalised_at);
    }

    private function seedLaborRate(User $owner, string $name = 'Duplex Receptacle'): void
    {
        PriceBookItem::create([
            'user_id' => $owner->id,
            'match_key' => PriceBookLine::keyFor($name),
            'unit' => 'ea',
            'description' => $name,
            'unit_material_cost' => 5.0,
            'unit_manhours' => 0.5,
            'sample_count' => 1,
        ]);
    }

    public function test_store_job_creates_the_job_and_prices_it(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        $this->seedLaborRate($owner);
        $result = $this->makeResult($project, $owner, ['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100]);
        $this->makeReview($result, $project, ['status' => SymbolReview::STATUS_APPROVED, 'final_count' => 5]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/job", [
                'team_id' => $team->id,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-15',
            ])
            ->assertCreated();

        $this->assertNotNull($response->json('data.jobId'));
        $this->assertDatabaseHas('work_jobs', ['id' => $response->json('data.jobId'), 'ai_result_id' => $result->id, 'team_id' => $team->id]);
    }

    public function test_store_job_refuses_before_the_review_is_finalised(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        $this->makeResult($project, $owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/job", [
                'team_id' => $team->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-15',
            ])
            ->assertStatus(422);
    }

    public function test_store_job_requires_a_team_and_dates(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $result = $this->makeResult($project, $owner, [], ['review_status' => AiResult::REVIEW_FINALISED, 'finalised_at' => now(), 'final_payload' => ['final_counts' => ['Duplex Receptacle' => 5]]]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/job", [])
            ->assertStatus(422);
        unset($result);
    }

    // --- Job task setup -------------------------------------------------------

    private function makeJobWithEstimate(User $owner, Project $project, Team $team): array
    {
        $this->seedLaborRate($owner);
        $result = $this->makeResult($project, $owner, ['status' => AiJob::STATUS_SUCCEEDED, 'progress' => 100]);
        $this->makeReview($result, $project, ['status' => SymbolReview::STATUS_APPROVED, 'final_count' => 5]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/finalise")
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/takeoffs/{$project->id}/job", [
                'team_id' => $team->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-15',
            ])
            ->assertCreated();

        return [\App\Models\Job::find($response->json('data.jobId')), $result];
    }

    public function test_task_setup_options_lists_the_jobs_crew_and_claimable_lines(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'team_id' => $team->id, 'role' => Foreman::ROLE_JOURNEYMAN]);
        Foreman::create(['name' => 'Sam Ortiz', 'initials' => 'SO', 'team_id' => $team->id, 'role' => Foreman::ROLE_FOREMAN]);
        [$job] = $this->makeJobWithEstimate($owner, $project, $team);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/jobs/{$job->id}/task-setup")
            ->assertOk();

        $this->assertSame('Jamie Lin', $response->json('data.foremen.0.name'));
        $this->assertSame('Sam Ortiz', $response->json('data.supervisors.0.name'));
        $this->assertSame('Crew A', $response->json('data.team.name'));
        $this->assertNotEmpty($response->json('data.estimateLines'));
    }

    public function test_task_setup_store_creates_tasks_and_claims_lines(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        $journeyman = Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'team_id' => $team->id, 'role' => Foreman::ROLE_JOURNEYMAN]);
        $foreman = Foreman::create(['name' => 'Sam Ortiz', 'initials' => 'SO', 'team_id' => $team->id, 'role' => Foreman::ROLE_FOREMAN]);
        [$job] = $this->makeJobWithEstimate($owner, $project, $team);

        $lineIds = EstimateItem::whereIn('estimate_id', $job->estimates()->pluck('id'))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->pluck('id')
            ->all();
        $this->assertNotEmpty($lineIds);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/jobs/{$job->id}/task-setup", [
                'tasks' => [[
                    'title' => 'Install receptacles',
                    'foreman_id' => $journeyman->id,
                    'supervisor_id' => $foreman->id,
                    'estimate_item_ids' => $lineIds,
                ]],
            ])
            ->assertCreated();

        $this->assertCount(1, $response->json('data.taskIds'));
        $this->assertDatabaseHas('job_tasks', ['job_id' => $job->id, 'title' => 'Install receptacles', 'foreman_id' => $journeyman->id, 'supervisor_id' => $foreman->id]);
        $this->assertDatabaseHas('estimate_items', ['id' => $lineIds[0], 'job_task_id' => $response->json('data.taskIds.0')]);
    }

    public function test_task_setup_store_refuses_staff_off_the_jobs_crew(): void
    {
        $owner = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        $otherTeam = Team::create(['name' => 'Crew B']);
        $offCrewJourneyman = Foreman::create(['name' => 'Off Crew', 'initials' => 'OC', 'team_id' => $otherTeam->id, 'role' => Foreman::ROLE_JOURNEYMAN]);
        $foreman = Foreman::create(['name' => 'Sam Ortiz', 'initials' => 'SO', 'team_id' => $team->id, 'role' => Foreman::ROLE_FOREMAN]);
        [$job] = $this->makeJobWithEstimate($owner, $project, $team);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/jobs/{$job->id}/task-setup", [
                'tasks' => [[
                    'title' => 'Install receptacles',
                    'foreman_id' => $offCrewJourneyman->id,
                    'supervisor_id' => $foreman->id,
                    'estimate_item_ids' => [],
                ]],
            ])
            ->assertStatus(422);
    }

    public function test_task_setup_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $project = $this->makeProject($owner);
        $team = Team::create(['name' => 'Crew A']);
        [$job] = $this->makeJobWithEstimate($owner, $project, $team);

        // Switching from `$owner`'s bearer token (used inside
        // makeJobWithEstimate) to `$other`'s within the same test — the
        // Sanctum guard caches the first resolved user otherwise, same as
        // every other mobile test file that switches actors mid-test.
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/jobs/{$job->id}/task-setup")
            ->assertForbidden();
    }
}
