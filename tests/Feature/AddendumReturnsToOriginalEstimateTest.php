<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finishing "Upload Addendum" lands back on the original estimate's own
 * screen — not on the addendum's own, separate page. The addendum's upload
 * was started from there, and combining it into a job happens there too.
 */
class AddendumReturnsToOriginalEstimateTest extends TestCase
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

        $this->original = app(EstimateBuilder::class)->fromFinalJson(
            $this->buildResult(),
            $this->user,
        );
    }

    public function test_finishing_an_addendums_review_redirects_to_the_original_estimate(): void
    {
        $addendumResult = $this->buildResult(addendumFor: $this->original);

        $response = $this->actingAs($this->user)
            ->post(route('finals.estimate', $addendumResult));

        $addendum = Estimate::where('parent_estimate_id', $this->original->id)->sole();
        $this->assertSame(Estimate::KIND_ADDENDUM, $addendum->kind);

        $response->assertRedirect(route('estimates.show', ['estimate' => $this->original->id, 'flow' => 1]));
    }

    /** The ordinary (non-addendum) path is unaffected — it still lands on its own new estimate. */
    public function test_finishing_a_standalone_takeoffs_review_still_redirects_to_its_own_estimate(): void
    {
        $result = $this->buildResult();

        $response = $this->actingAs($this->user)
            ->post(route('finals.estimate', $result));

        $estimate = Estimate::where('ai_result_id', $result->id)->sole();
        $response->assertRedirect(route('estimates.show', ['estimate' => $estimate->id, 'flow' => 1]));
    }

    private function buildResult(?Estimate $addendumFor = null): AiResult
    {
        $aiJob = AiJob::create([
            'project_id' => $this->projectId,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $this->projectId,
            'addendum_for_estimate_id' => $addendumFor?->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
            'review_status' => AiResult::REVIEW_FINALISED,
        ]);

        $result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 1,
            'confidence' => 0.9,
        ]);

        return $result;
    }
}
