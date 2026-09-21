<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reported bug: from the normal Estimate screen (not Addendum), selecting
 * Draft estimates/addenda in the pre-job "Select Estimates & Addendums"
 * workspace and clicking through "Continue to Job" -> "Create Job" left the
 * selected Draft estimates as Draft — unlike the Addendum screen's own
 * "Create Job", which correctly approved them.
 *
 * Root cause: `EstimateShow.tsx`'s "Continue to Job" link only appended
 * `?merge_estimates=` once a job already existed (`!beforeJob &&
 * addenda.length > 0`), but the merge-selection checkboxes are only shown
 * *before* a job exists (`beforeJob`). So the one case the picker exists for
 * never actually sent the selection — `FinalSymbols` posted an empty
 * `estimate_ids`, `FinalTakeoffController::storeJob` fell through to the
 * plain single-estimate branch, and every selected addendum was silently
 * dropped: never merged, never approved.
 *
 * Fix: the href now appends `?merge_estimates=` whenever there are addenda at
 * all, regardless of `beforeJob` — the same condition the Addendum screen's
 * "Create Job" already relied on `EstimateMergeJobBuilder` for. No new
 * approval logic was added; this exercises the existing, shared
 * `storeJob` -> `storeMergedJob` -> `EstimateMergeJobBuilder::build()` path
 * that the Addendum screen already used, now reachable from this screen too.
 */
class EstimateRoadmapContinueToJobApprovesSelectedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Client $client;

    private $address;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->project = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);

        $this->client = Client::create(['user_id' => $this->user->id, 'name' => 'Harborview Data Hall']);
        $this->project->update(['client_id' => $this->client->id]);
        $this->address = $this->client->addresses()->create(['address' => '1 Harborview Way', 'is_primary' => true]);
        $this->team = Team::create(['name' => 'North Crew']);
    }

    /** 1 & 5: a single selected Draft becomes Approved, and the job is created. */
    public function test_a_single_selected_draft_estimate_becomes_approved_and_the_job_is_created(): void
    {
        $original = $this->estimateWith('Original scope', 500);

        $this->postContinueToJob([$original->id], 'Harborview — Phase 1')
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $original->fresh()->status);
        $this->assertTrue(Job::where('name', 'Harborview — Phase 1')->exists());
    }

    /** 2: every selected Draft (original + addenda) becomes Approved. */
    public function test_multiple_selected_draft_estimates_all_become_approved(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum1 = $this->estimateWith('Addendum 1 scope', 200, $original);
        $addendum2 = $this->estimateWith('Addendum 2 scope', 150, $original);

        $this->postContinueToJob([$original->id, $addendum1->id, $addendum2->id], 'Harborview — Combined')
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $original->fresh()->status);
        $this->assertSame('approved', $addendum1->fresh()->status);
        $this->assertSame('approved', $addendum2->fresh()->status);
    }

    /** 3: a Draft addendum left unchecked stays Draft. */
    public function test_an_unselected_draft_addendum_remains_draft(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum1 = $this->estimateWith('Addendum 1 scope', 200, $original);
        $unselected = $this->estimateWith('Addendum 2 scope', 150, $original);

        $this->postContinueToJob([$original->id, $addendum1->id], 'Harborview — Partial')
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $original->fresh()->status);
        $this->assertSame('approved', $addendum1->fresh()->status);
        $this->assertSame('draft', $unselected->fresh()->status);
    }

    /** 4: an already-Approved source selected again is left unchanged. */
    public function test_an_already_approved_selected_estimate_is_not_touched_again(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $approvedAddendum = $this->estimateWith('Addendum scope', 200, $original, status: 'approved');
        $touchedAt = $approvedAddendum->updated_at;

        $this->postContinueToJob([$original->id, $approvedAddendum->id], 'Harborview — With Approved Addendum')
            ->assertSessionHasNoErrors();

        $approvedAddendum->refresh();
        $this->assertSame('approved', $approvedAddendum->status);
        $this->assertTrue($touchedAt->equalTo($approvedAddendum->updated_at));
    }

    /** 5: the created job references exactly the selected sources. */
    public function test_the_created_job_references_only_the_selected_estimates(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum1 = $this->estimateWith('Addendum 1 scope', 200, $original);
        $this->estimateWith('Addendum 2 scope', 150, $original); // left unselected

        $this->postContinueToJob([$original->id, $addendum1->id], 'Harborview — Referenced')
            ->assertSessionHasNoErrors();

        $job = Job::where('name', 'Harborview — Referenced')->sole();
        $merged = $job->estimates()->sole();
        $sourceIds = $merged->mergeSources()->pluck('estimates.id')->sort()->values()->all();

        $this->assertSame([$original->id, $addendum1->id], $sourceIds);
        $this->assertSame('700.00', $merged->grand_total);
    }

    /** 6: the Approved status persists in the database and survives a fresh page load. */
    public function test_the_approved_status_persists_after_a_refresh(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum1 = $this->estimateWith('Addendum 1 scope', 200, $original);

        $this->postContinueToJob([$original->id, $addendum1->id], 'Harborview — Persisted')
            ->assertSessionHasNoErrors();

        // Straight from the database, not just the redirect response.
        $this->assertSame('approved', Estimate::find($original->id)->status);
        $this->assertSame('approved', Estimate::find($addendum1->id)->status);

        // And a fresh page load reflects it too.
        $this->actingAs($this->user)
            ->get(route('estimates.show', $original))
            ->assertInertia(fn (Assert $page) => $page
                ->where('estimate.status', 'approved'));
    }

    private function postContinueToJob(array $estimateIds, string $name)
    {
        $result = $this->buildFinalisedAiResult();

        return $this->actingAs($this->user)->post(route('finals.job', $result), [
            'estimate_ids' => $estimateIds,
            'name' => $name,
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
    }

    private function buildFinalisedAiResult(): AiResult
    {
        $aiJob = AiJob::create([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        return AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $this->project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
            'review_status' => AiResult::REVIEW_FINALISED,
        ]);
    }

    private function estimateWith(string $description, float $amount, ?Estimate $parent = null, string $status = 'draft'): Estimate
    {
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'parent_estimate_id' => $parent?->id,
            'addendum_number' => $parent !== null ? Estimate::nextAddendumNumber($parent->id) : null,
            'number' => Estimate::nextNumber($this->user),
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => $status,
            'kind' => $parent === null ? Estimate::KIND_STANDALONE : Estimate::KIND_ADDENDUM,
            'amount' => 0,
        ]);

        $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => $description,
            'unit' => 'hr',
            'quantity' => 1,
            'unit_cost' => $amount,
            'position' => 1,
        ]);
        $estimate->recalculateTotals();

        return $estimate;
    }
}
