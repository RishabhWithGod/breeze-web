<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\SymbolReview;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing off an addendum's review is what actually raises its estimate in
 * the real flow (`AiReviewController::finalise()`, not the separate manual
 * `finals.estimate` endpoint) — so this is the redirect that has to land back
 * on the original estimate, not a second, standalone page for the addendum.
 */
class AddendumFinaliseReturnsToOriginalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $projectId;

    private Estimate $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $project = $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);
        $this->projectId = $project->id;

        // The project's original, already-priced estimate — primed directly
        // (final_payload + finalSymbols), not through the HTTP finalise flow
        // this test class is actually exercising.
        $originalResult = $this->buildAiJobAndResult();
        $originalResult->update(['final_payload' => ['final_counts' => []]]);
        $originalResult->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);
        $this->original = app(EstimateBuilder::class)->fromFinalJson($originalResult, $this->user);
    }

    public function test_signing_off_an_addendums_review_redirects_to_the_original_estimate(): void
    {
        $addendumResult = $this->buildPendingReview(addendumFor: $this->original);

        $response = $this->actingAs($this->user)
            ->post(route('reviews.finalise', $addendumResult));

        $addendum = Estimate::where('parent_estimate_id', $this->original->id)->sole();
        $this->assertSame(Estimate::KIND_ADDENDUM, $addendum->kind);
        $this->assertSame($addendumResult->id, $addendum->ai_result_id);

        $response->assertRedirect(route('estimates.show', ['estimate' => $this->original->id, 'flow' => 1]));
    }

    /** The ordinary (non-addendum) path is unaffected — it still lands on its own new estimate. */
    public function test_signing_off_a_standalone_review_still_redirects_to_its_own_estimate(): void
    {
        $result = $this->buildPendingReview();

        $response = $this->actingAs($this->user)
            ->post(route('reviews.finalise', $result));

        $estimate = Estimate::where('ai_result_id', $result->id)->sole();
        $response->assertRedirect(route('estimates.show', ['estimate' => $estimate->id, 'flow' => 1]));
    }

    private function buildAiJobAndResult(?Estimate $addendumFor = null): AiResult
    {
        $aiJob = AiJob::create([
            'project_id' => $this->projectId,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        return AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $this->projectId,
            'addendum_for_estimate_id' => $addendumFor?->id,
            'original_payload' => [],
        ]);
    }

    /** One approved symbol review, ready to be signed off through the real HTTP endpoint. */
    private function buildPendingReview(?Estimate $addendumFor = null): AiResult
    {
        $result = $this->buildAiJobAndResult($addendumFor);

        $result->reviews()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'ai_name' => 'EM2',
            'ai_count' => 2,
            'final_count' => 2,
            'status' => SymbolReview::STATUS_APPROVED,
        ]);

        return $result;
    }
}
