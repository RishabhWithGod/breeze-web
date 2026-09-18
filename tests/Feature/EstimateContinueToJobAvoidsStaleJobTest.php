<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reported bug: Sidebar Addendum -> Upload Addendum -> Review -> Estimate ->
 * Create Job opened a job form prefilled with an *existing* job's data
 * instead of a fresh one.
 *
 * Root cause: once the original estimate already has a job, "Continue to
 * Job" pointed unconditionally at `FinalTakeoffController::show`, which seeds
 * its form from `AiResult->workJob` on purpose (so revisiting that step edits
 * the job it already raised). That is correct for the plain one-takeoff flow,
 * but wrong once an addendum exists too — this asserts the estimate screen
 * carries the data the frontend needs to route away from that stale-job form
 * in exactly that case (`EstimateShow.tsx`'s `!beforeJob && addenda.length >
 * 0` branch), and that the one true "always fresh" job-creation entry point
 * (`/jobs/from-estimates`, exercised end-to-end in
 * `JobFromSelectedEstimatesTest`) is what it lands on instead.
 */
class EstimateContinueToJobAvoidsStaleJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->project = $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);
    }

    public function test_estimate_screen_flags_a_stale_job_once_an_addendum_exists_so_the_frontend_avoids_it(): void
    {
        // The original takeoff already has a job raised from it — the
        // scenario where `FinalTakeoffController::show` would prefill an
        // existing job's name/location/dates/team into the form.
        $originalResult = $this->buildAiJobAndResult();
        $originalResult->finalSymbols()->create([
            'project_id' => $this->project->id,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);
        $original = app(EstimateBuilder::class)->fromFinalJson($originalResult, $this->user);

        $existingJob = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Harborview — First Phase',
            'status' => 'in-progress',
        ]);
        $original->update(['job_id' => $existingJob->id]);
        $originalResult->update(['work_job_id' => $existingJob->id]);

        // Now an addendum is uploaded and finalised for that same estimate —
        // the exact repro path (sidebar Addendum -> Upload -> Review).
        $addendumResult = $this->buildPendingReview(addendumFor: $original);
        $this->actingAs($this->user)->post(route('reviews.finalise', $addendumResult))
            ->assertRedirect(route('estimates.show', ['estimate' => $original->id, 'flow' => 1]));

        $addendum = Estimate::where('parent_estimate_id', $original->id)->sole();
        $this->assertSame(Estimate::KIND_ADDENDUM, $addendum->kind);

        // Landing back on the original estimate: the props the frontend's
        // "Continue to Job" routing decision reads must show a job already
        // exists (`beforeJob` false, `jobId` set) *and* that an addendum is
        // now present — together, exactly the condition that must steer
        // away from `finalSymbols` (the stale-job form) and towards the
        // Addendum screen's always-fresh "Create Job".
        $this->actingAs($this->user)
            ->get(route('estimates.show', ['estimate' => $original->id, 'flow' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('EstimateShow')
                ->where('beforeJob', false)
                ->where('estimate.jobId', $existingJob->id)
                ->where('estimate.projectId', $this->project->id)
                ->has('addenda', 1));

        // The existing job is completely untouched by any of this.
        $this->assertSame('in-progress', $existingJob->fresh()->status);
    }

    private function buildAiJobAndResult(?Estimate $addendumFor = null): AiResult
    {
        $aiJob = AiJob::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $this->project->id,
            'addendum_for_estimate_id' => $addendumFor?->id,
            'original_payload' => [],
        ]);

        $result->update(['final_payload' => ['final_counts' => []]]);

        return $result;
    }

    private function buildPendingReview(?Estimate $addendumFor = null): AiResult
    {
        $result = $this->buildAiJobAndResult($addendumFor);

        $result->reviews()->create([
            'project_id' => $this->project->id,
            'name' => 'EM2',
            'ai_name' => 'EM2',
            'ai_count' => 2,
            'final_count' => 2,
            'status' => SymbolReview::STATUS_APPROVED,
        ]);

        return $result;
    }
}
