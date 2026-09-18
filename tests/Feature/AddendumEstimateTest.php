<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A second takeoff, raised as an addendum for an existing estimate: it never
 * touches the original, gets its own `Estimate` row (`kind = addendum`,
 * `addendum_number` counting up), and is just as editable — and just as much
 * "the saved figure, not the AI's" — as any other estimate.
 *
 * Uses the same direct-fixture pattern as `PriceBookEstimatingTest`
 * (`AiJob`/`AiResult`/`FinalSymbol` built by hand, `EstimateBuilder::fromFinalJson()`
 * called straight) rather than a real PDF/engine call.
 */
class AddendumEstimateTest extends TestCase
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

        $this->original = $this->buildEstimateFromTakeoff();
    }

    public function test_uploading_an_addendum_creates_a_separate_estimate_linked_to_the_original(): void
    {
        $addendum = $this->buildEstimateFromTakeoff(addendumFor: $this->original);

        $this->assertNotSame($this->original->id, $addendum->id);
        $this->assertSame(Estimate::KIND_ADDENDUM, $addendum->kind);
        $this->assertSame($this->original->id, $addendum->parent_estimate_id);
        $this->assertSame(1, $addendum->addendum_number);

        // The original is untouched — still standalone, own line items intact.
        $this->original->refresh();
        $this->assertSame(Estimate::KIND_STANDALONE, $this->original->kind);
        $this->assertNull($this->original->parent_estimate_id);
        $this->assertSame(1, $this->original->items()->count());
    }

    public function test_a_second_addendum_for_the_same_estimate_is_numbered_after_the_first(): void
    {
        $this->buildEstimateFromTakeoff(addendumFor: $this->original);
        $second = $this->buildEstimateFromTakeoff(addendumFor: $this->original);

        $this->assertSame(2, $second->addendum_number);
        $this->assertSame(2, $this->original->refresh()->addenda()->count());
    }

    public function test_the_addendums_ai_total_is_not_final_until_the_user_edits_and_saves_it(): void
    {
        $addendum = $this->buildEstimateFromTakeoff(addendumFor: $this->original);
        $item = $addendum->items()->sole();
        $totalBeforeEdit = $addendum->grand_total;

        $this->actingAs($this->user)
            ->put("/estimates/{$addendum->id}/items/{$item->id}", [
                'category' => $item->category,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_cost' => 200,
            ])
            ->assertSessionHasNoErrors();

        // The line now carries the saved, user-typed rate — not whatever the
        // AI originally priced it at.
        $this->assertSame('200.0000', $item->fresh()->unit_cost);
        $this->assertNotSame($totalBeforeEdit, $addendum->fresh()->grand_total);
    }

    /** An existing job built from the original is never touched by a later addendum. */
    public function test_an_existing_jobs_estimate_is_untouched_by_a_later_addendum(): void
    {
        $job = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->projectId,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — Phase 1',
            'status' => 'planning',
        ]);
        $this->original->update(['job_id' => $job->id]);
        app(EstimateBuilder::class)->syncJobBudget($this->original->refresh());
        $budgetBefore = $job->fresh()->budget;

        $this->buildEstimateFromTakeoff(addendumFor: $this->original);

        $this->assertSame($budgetBefore, $job->fresh()->budget);
        $this->assertSame($this->original->items()->count(), $this->original->fresh()->items()->count());
        $this->assertSame($job->id, $this->original->fresh()->job_id);
    }

    /** Builds one estimate from a fresh takeoff fixture — a standalone one, or an addendum when `addendumFor` is given. */
    private function buildEstimateFromTakeoff(?Estimate $addendumFor = null): Estimate
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
        ]);

        $result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);

        return app(EstimateBuilder::class)->fromFinalJson($result, $this->user);
    }
}
