<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Continue to Job" from an estimate that already has addenda to fold in
 * reuses this same roadmap step/form (`FinalTakeoffController`) rather than a
 * second UI — but must open it completely fresh and raise a brand-new job,
 * never silently reopen and resave whatever job this takeoff already has.
 *
 * The ordinary, non-addendum use of this same screen (revisit a takeoff's own
 * job to edit it) is exercised elsewhere (`JobTaskSetupTest`) and is
 * untouched by any of this — these tests only cover the `?merge_estimates=`
 * / `estimate_ids[]` branch.
 */
class FinalTakeoffMergedJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Project $project;

    private ClientAddress $address;

    private Team $team;

    private AiResult $originalResult;

    private Estimate $original;

    private Estimate $addendum;

    private Job $existingJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->client = Client::create(['user_id' => $this->user->id, 'name' => 'Harborview Data Hall']);
        $this->address = $this->client->addresses()->create([
            'address' => '1 Harborview Way',
            'is_primary' => true,
        ]);
        $this->project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);
        $this->team = Team::create(['name' => 'North Crew']);

        // The original takeoff already produced an estimate and, from it, a
        // job — exactly the state that made `storeJob` silently reuse/refresh
        // that job instead of raising a new one.
        $aiJob = AiJob::create(['project_id' => $this->project->id, 'user_id' => $this->user->id, 'status' => 'completed']);
        $this->originalResult = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $this->project->id,
            'original_payload' => [],
        ]);
        $this->originalResult->update([
            'final_payload' => ['final_counts' => []],
            'review_status' => AiResult::REVIEW_FINALISED,
        ]);
        $this->originalResult->finalSymbols()->create([
            'project_id' => $this->project->id,
            'name' => 'EM2',
            'count' => 2,
            'confidence' => 0.9,
        ]);
        $this->original = app(EstimateBuilder::class)->fromFinalJson($this->originalResult, $this->user);
        $this->original->items()->sole()->update(['unit_cost' => 20000]);
        // Zeroed so this test's totals are plain sums — the rate book's own
        // markup/tax defaults are not what this test is about.
        $this->original->update(['markup_pct' => 0, 'tax_pct' => 0]);
        $this->original->recalculateTotals();

        $this->existingJob = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'name' => 'Harborview — First Phase',
            'status' => 'in-progress',
            'budget' => '20000.00',
        ]);
        $this->original->update(['job_id' => $this->existingJob->id]);
        $this->originalResult->update(['work_job_id' => $this->existingJob->id]);

        // A separate addendum, raised for the same original, with its own saved total.
        $this->addendum = Estimate::create([
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
            'parent_estimate_id' => $this->original->id,
            'addendum_number' => Estimate::nextAddendumNumber($this->original->id),
            'number' => Estimate::nextNumber($this->user),
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => Estimate::KIND_ADDENDUM,
            'amount' => 0,
        ]);
        $this->addendum->items()->create([
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => 'Addendum scope',
            'unit' => 'hr',
            'quantity' => 1,
            'unit_cost' => 5000,
            'position' => 1,
        ]);
        $this->addendum->recalculateTotals();
    }

    public function test_the_job_step_opens_fresh_when_addenda_are_being_folded_in(): void
    {
        $this->actingAs($this->user)
            ->get(route('finals.show', $this->originalResult).'?merge_estimates='.
                "{$this->original->id},{$this->addendum->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('FinalSymbols')
                ->where('result.workJobId', null)
                ->where('result.job', null)
                ->where('result.mergeEstimateIds', [$this->original->id, $this->addendum->id]));
    }

    public function test_the_ordinary_job_step_is_unaffected_when_there_is_nothing_to_merge(): void
    {
        // No `merge_estimates` at all — the plain "come back to my own job to
        // edit it" behaviour this screen has always had.
        $this->actingAs($this->user)
            ->get(route('finals.show', $this->originalResult))
            ->assertInertia(fn (Assert $page) => $page
                ->component('FinalSymbols')
                ->where('result.workJobId', $this->existingJob->id)
                ->where('result.job.name', $this->existingJob->name));
    }

    public function test_submitting_the_form_with_selected_estimates_raises_a_brand_new_unlinked_job(): void
    {
        $response = $this->actingAs($this->user)->post(route('finals.job', $this->originalResult), [
            'estimate_ids' => [$this->original->id, $this->addendum->id],
            'name' => 'Harborview — Combined Scope',
            'address_ids' => [$this->address->id],
            'description' => 'Combined original + addendum scope.',
            'job_type' => 'commercial',
            'team_id' => $this->team->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
        $response->assertSessionHasNoErrors();

        $newJob = Job::where('name', 'Harborview — Combined Scope')->sole();
        $response->assertRedirect(route('jobs.tasks.setup', $newJob));

        // A genuinely new, independent job.
        $this->assertNotSame($this->existingJob->id, $newJob->id);
        $this->assertSame(2, Job::count());
        $this->assertSame('commercial', $newJob->job_type);
        $this->assertSame('Combined original + addendum scope.', $newJob->description);

        // Budget = the two selected sources' saved totals, summed — the
        // original's edited 40000 (2 x 20000, its symbol's count) + the
        // addendum's 5000.
        $merged = $newJob->estimates()->sole();
        $this->assertSame('45000.00', $merged->grand_total);
        $this->assertSame('45000.00', $newJob->fresh()->budget);
        $this->assertSame(2, $merged->items()->count());

        // Both selected sources flip to Approved.
        $this->assertSame('approved', $this->original->fresh()->status);
        $this->assertSame('approved', $this->addendum->fresh()->status);

        // The takeoff's original job is completely untouched — not resaved,
        // not relinked, not renamed.
        $this->existingJob->refresh();
        $this->assertSame('Harborview — First Phase', $this->existingJob->name);
        $this->assertSame('in-progress', $this->existingJob->status);
        $this->assertSame('20000.00', $this->existingJob->budget);
        $this->assertSame($this->existingJob->id, $this->originalResult->fresh()->work_job_id);
    }

    public function test_an_existing_completed_job_does_not_block_this_either(): void
    {
        $this->existingJob->update(['status' => Job::STATUS_COMPLETED]);

        $response = $this->actingAs($this->user)->post(route('finals.job', $this->originalResult), [
            'estimate_ids' => [$this->original->id, $this->addendum->id],
            'name' => 'Harborview — Combined Scope',
            'address_ids' => [$this->address->id],
            'team_id' => $this->team->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Job::count());
        $this->assertSame(Job::STATUS_COMPLETED, $this->existingJob->fresh()->status);
    }
}
