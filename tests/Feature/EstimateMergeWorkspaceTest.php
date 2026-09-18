<?php

namespace Tests\Feature;

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
 * The Estimate screen's pre-job merge workspace: line items broken out by
 * source (original + each addendum, each with its own saved total), "Add a
 * Line" hidden, and a "Create Job from Estimates" selection — all of it gated
 * on the one state it applies to (`beforeJob`). Once a job exists, or on an
 * estimate that is itself an addendum/merge, the screen is exactly what it
 * was before this change.
 */
class EstimateMergeWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

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
    }

    public function test_the_merge_workspace_is_offered_before_a_job_exists(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum = $this->estimateWith('Addendum scope', 200, $original);

        $this->actingAs($this->user)
            ->get("/estimates/{$original->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('beforeJob', true)
                ->where('addenda.0.id', $addendum->id)
                ->where('addenda.0.items.0.description', 'Addendum scope'));
    }

    public function test_the_merge_workspace_is_not_offered_once_a_job_exists(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $job = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — Phase 1',
            'status' => 'planning',
        ]);
        $original->update(['job_id' => $job->id]);

        $this->actingAs($this->user)
            ->get("/estimates/{$original->id}")
            ->assertInertia(fn (Assert $page) => $page->where('beforeJob', false));
    }

    /**
     * Once a job exists, the per-source line-item breakdown (and "Add a
     * Line" being hidden) is driven purely by whether addenda exist — the
     * data for it (each addendum's own items) is still sent either way.
     */
    public function test_addenda_and_their_items_are_still_sent_once_a_job_exists(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum = $this->estimateWith('Addendum scope', 200, $original);
        $job = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — Phase 1',
            'status' => 'planning',
        ]);
        $original->update(['job_id' => $job->id]);

        $this->actingAs($this->user)
            ->get("/estimates/{$original->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('beforeJob', false)
                ->where('addenda.0.id', $addendum->id)
                ->where('addenda.0.items.0.description', 'Addendum scope'));
    }

    public function test_the_merge_workspace_is_not_offered_on_an_addendum_itself(): void
    {
        $original = $this->estimateWith('Original scope', 500);
        $addendum = $this->estimateWith('Addendum scope', 200, $original);

        $this->actingAs($this->user)
            ->get("/estimates/{$addendum->id}")
            ->assertInertia(fn (Assert $page) => $page->where('beforeJob', false));
    }

    /** The full path this screen exists for: pick a subset, raise a job, only the selected sources count. */
    public function test_creating_a_job_from_this_screens_data_uses_only_the_selected_estimates(): void
    {
        $client = Client::create(['user_id' => $this->user->id, 'name' => 'Harborview Data Hall']);
        $address = $client->addresses()->create(['address' => '1 Harborview Way', 'is_primary' => true]);
        $this->project->update(['client_id' => $client->id]);
        $team = Team::create(['name' => 'North Crew']);

        $original = $this->estimateWith('Original scope', 500);
        $addendum1 = $this->estimateWith('Addendum 1 scope', 200, $original);
        $this->estimateWith('Addendum 2 scope', 300, $original); // left unselected

        $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$original->id, $addendum1->id],
            'name' => 'Harborview — Combined Scope',
            'team_id' => $team->id,
            'address_ids' => [$address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        $merged = Job::where('name', 'Harborview — Combined Scope')->sole()->estimates()->sole();
        $this->assertSame('700.00', $merged->grand_total);
        $this->assertSame(2, $merged->items()->count());
        $this->assertFalse($merged->items()->where('description', 'Addendum 2 scope')->exists());
    }

    private function estimateWith(string $description, float $amount, ?Estimate $parent = null): Estimate
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
