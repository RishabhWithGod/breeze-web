<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
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
 * Selecting which estimates go into a job: the Addendum screen lists every
 * standalone estimate and its addenda with their own totals, and only the
 * ones actually checked are combined — never a silent "all of them", and
 * never a permanent change to the sources themselves.
 */
class JobFromSelectedEstimatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private ClientAddress $address;

    private Team $team;

    private Estimate $original;

    private Estimate $addendum1;

    private Estimate $addendum2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);

        $client = Client::create(['user_id' => $this->user->id, 'name' => 'Harborview Data Hall']);
        $this->address = $client->addresses()->create([
            'address' => '1 Harborview Way',
            'is_primary' => true,
        ]);

        $this->project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);

        $this->team = Team::create(['name' => 'North Crew']);

        $this->original = $this->estimateWith(500, Estimate::KIND_STANDALONE);
        $this->addendum1 = $this->estimateWith(200, Estimate::KIND_ADDENDUM, $this->original);
        $this->addendum2 = $this->estimateWith(300, Estimate::KIND_ADDENDUM, $this->original);
    }

    public function test_the_addendum_screen_lists_the_original_and_every_addendum_with_its_own_total(): void
    {
        $this->actingAs($this->user)
            ->get("/estimates/addenda?project={$this->project->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('Addendum/Index')
                ->has('originals', 1)
                ->where('originals.0.id', $this->original->id)
                ->where('originals.0.amount', 500)
                ->has('originals.0.addenda', 2)
                ->where('originals.0.addenda.0.addendumNumber', 1)
                ->where('originals.0.addenda.0.amount', 200)
                ->where('originals.0.addenda.1.addendumNumber', 2)
                ->where('originals.0.addenda.1.amount', 300));
    }

    public function test_a_job_created_from_the_original_and_one_selected_addendum_totals_only_those_two(): void
    {
        $response = $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum2->id],
            'name' => 'Harborview — Combined Scope',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();
        $job = Job::where('name', 'Harborview — Combined Scope')->sole();
        $response->assertRedirect(route('jobs.tasks.setup', $job));

        $merged = $job->estimates()->sole();
        $this->assertSame(Estimate::KIND_MERGED, $merged->kind);
        $this->assertSame('800.00', $merged->grand_total);
        $this->assertSame('800.00', $job->fresh()->budget);

        // Only the original's and addendum 2's lines — addendum 1's $200 line
        // is absent, not folded in.
        $descriptions = $merged->items()->pluck('description')->all();
        $this->assertContains('Original scope item', $descriptions);
        $this->assertContains('Addendum scope item', $descriptions);
        $this->assertSame(2, $merged->items()->count());

        $sourceIds = $merged->mergeSources()->pluck('estimates.id')->sort()->values()->all();
        $this->assertSame([$this->original->id, $this->addendum2->id], $sourceIds);

        // The sources themselves are untouched — read, not reassigned.
        $this->assertNull($this->original->fresh()->job_id);
        $this->assertSame('500.00', $this->original->fresh()->grand_total);
        $this->assertSame('300.00', $this->addendum2->fresh()->grand_total);
        $this->assertNull($this->addendum1->fresh()->job_id);

        // The existing, unmodified task-setup screen picks up the merged
        // estimate's lines through `$job->estimates()` with no changes of its own.
        $this->actingAs($this->user)->get(route('jobs.tasks.setup', $job))->assertOk();
    }

    public function test_an_identical_line_on_two_selected_sources_has_its_quantity_combined_not_duplicated(): void
    {
        // Both estimates separately priced the same fixture the same way —
        // the addendum re-counted something the original already had.
        $this->original->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Shared Fixture',
            'unit' => 'ea',
            'quantity' => 2,
            'unit_cost' => 10,
            'position' => 2,
        ]);
        $this->original->recalculateTotals();

        $this->addendum2->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Shared Fixture',
            'unit' => 'ea',
            'quantity' => 3,
            'unit_cost' => 10,
            'position' => 2,
        ]);
        $this->addendum2->recalculateTotals();

        $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum2->id],
            'name' => 'Harborview — Combined Scope',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        $merged = Job::where('name', 'Harborview — Combined Scope')->sole()->estimates()->sole();
        $shared = $merged->items()->where('description', 'Shared Fixture')->sole();

        $this->assertSame('5.0000', $shared->quantity);
    }

    public function test_estimates_from_a_different_project_cannot_be_combined(): void
    {
        $otherProject = Project::create([
            'user_id' => $this->user->id,
            'name' => 'A Different Site',
            'client' => 'A Different Site',
            'status' => 'draft',
        ]);
        $foreign = Estimate::create([
            'project_id' => $otherProject->id,
            'number' => 'EST-7777',
            'client' => 'A Different Site',
            'project' => 'A Different Site',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0,
        ]);

        $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $foreign->id],
            'name' => 'Should Not Be Created',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ])->assertStatus(422);

        $this->assertSame(0, Job::count());
    }

    /**
     * The original estimate already belongs to an earlier job that is now
     * `completed` — the most locked a job can be. Building a second job from
     * a fresh selection must still succeed, must not touch that earlier job
     * in any way, and must not link the two: `Job::create()` never reads an
     * existing job id, so nothing here should be able to block or reuse one.
     */
    public function test_an_existing_completed_job_does_not_block_or_get_linked_to_a_new_job(): void
    {
        $existingJob = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client_id' => $this->address->client_id,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — First Phase',
            'team_id' => $this->team->id,
            'status' => Job::STATUS_COMPLETED,
            'budget' => '500.00',
        ]);
        $this->original->update(['job_id' => $existingJob->id]);

        $response = $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum2->id],
            'name' => 'Harborview — Second Phase',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();

        $newJob = Job::where('name', 'Harborview — Second Phase')->sole();
        $this->assertNotSame($existingJob->id, $newJob->id);
        $response->assertRedirect(route('jobs.tasks.setup', $newJob));

        $merged = $newJob->estimates()->sole();
        $this->assertSame('800.00', $merged->grand_total);
        $this->assertSame('800.00', $newJob->fresh()->budget);

        // The earlier job is completely unaffected — status, budget, and
        // row count all unchanged, and nothing on the new job points back to it.
        $existingJob->refresh();
        $this->assertSame(Job::STATUS_COMPLETED, $existingJob->status);
        $this->assertSame('500.00', $existingJob->budget);
        $this->assertSame(2, Job::count());
        $this->assertNotEquals($existingJob->id, $newJob->job_id ?? null);
    }

    /**
     * Same guarantee, but the earlier job is in an ordinary open status —
     * confirms the new-job creation never inspects any existing job's status
     * at all, whether locked or not.
     */
    public function test_an_existing_in_progress_job_does_not_block_a_new_job_either(): void
    {
        $existingJob = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client_id' => $this->address->client_id,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — First Phase',
            'team_id' => $this->team->id,
            'status' => 'in-progress',
            'budget' => '500.00',
        ]);
        $this->original->update(['job_id' => $existingJob->id]);

        $response = $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum1->id],
            'name' => 'Harborview — Third Phase',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();

        $newJob = Job::where('name', 'Harborview — Third Phase')->sole();
        $this->assertNotSame($existingJob->id, $newJob->id);

        $existingJob->refresh();
        $this->assertSame('in-progress', $existingJob->status);
        $this->assertSame('500.00', $existingJob->budget);
        $this->assertSame(2, Job::count());
    }

    private function estimateWith(float $amount, string $kind, ?Estimate $parent = null): Estimate
    {
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'parent_estimate_id' => $parent?->id,
            'addendum_number' => $parent !== null ? Estimate::nextAddendumNumber($parent->id) : null,
            'number' => Estimate::nextNumber($this->user),
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => $kind,
            'amount' => 0,
        ]);

        $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => $parent === null ? 'Original scope item' : 'Addendum scope item',
            'unit' => 'hr',
            'quantity' => 1,
            'unit_cost' => $amount,
            'position' => 1,
        ]);
        $estimate->recalculateTotals();

        return $estimate;
    }
}
