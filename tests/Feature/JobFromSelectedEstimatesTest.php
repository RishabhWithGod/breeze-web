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
