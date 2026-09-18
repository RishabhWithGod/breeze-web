<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Estimate screen's checked selection is the single source of truth for
 * everything downstream: the new job's budget/amount, what materials/labor/
 * tasks it starts with, and what an invoice raised against it bills — never
 * an unselected addendum's amount, never a stale/original AI total, and
 * never anything carried over from an unrelated existing job.
 */
class JobFromEstimatesMasterSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Project $project;

    private ClientAddress $address;

    private Team $team;

    private Estimate $original;

    private Estimate $addendum1;

    private Estimate $addendum2;

    private Estimate $addendum3;

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

        // Amounts deliberately distinct from any AI-original figure — each is
        // the estimator's own saved, edited total, exactly what must be summed.
        $this->original = $this->estimateWith(20000, Estimate::KIND_STANDALONE, null, 'Original scope');
        $this->addendum1 = $this->estimateWith(10000, Estimate::KIND_ADDENDUM, $this->original, 'Addendum 1 scope');
        $this->addendum2 = $this->estimateWith(5000, Estimate::KIND_ADDENDUM, $this->original, 'Addendum 2 scope');
        $this->addendum3 = $this->estimateWith(7084, Estimate::KIND_ADDENDUM, $this->original, 'Addendum 3 scope');
    }

    public function test_original_plus_addendum_one_and_three_produce_a_job_with_exactly_their_combined_total(): void
    {
        // Selected Total = 20000 + 10000 + 7084 = 37084 — Addendum 2 is left
        // unchecked and must not contribute a cent.
        $response = $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum1->id, $this->addendum3->id],
            'name' => 'Harborview — Selected Scope',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
        $response->assertSessionHasNoErrors();

        $job = Job::where('name', 'Harborview — Selected Scope')->sole();
        $merged = $job->estimates()->sole();

        // 1. Job Budget / Job Amount = exactly the Selected Total.
        $this->assertSame('37084.00', $merged->grand_total);
        $this->assertSame('37084.00', $merged->amount);
        $this->assertSame('37084.00', $job->fresh()->budget);

        // 2/3. Only the three selected sources' lines are present — Addendum
        // 2's line is completely absent, and nothing is duplicated.
        $descriptions = $merged->items()->pluck('description')->sort()->values()->all();
        $this->assertSame(['Addendum 1 scope', 'Addendum 3 scope', 'Original scope'], $descriptions);
        $this->assertSame(3, $merged->items()->count());

        $sourceIds = $merged->mergeSources()->pluck('estimates.id')->sort()->values()->all();
        $this->assertSame(
            collect([$this->original->id, $this->addendum1->id, $this->addendum3->id])->sort()->values()->all(),
            $sourceIds,
        );

        // 4. The three selected sources are Approved, never left Draft —
        // Addendum 2, never selected, is untouched.
        $this->assertSame('approved', $this->original->fresh()->status);
        $this->assertSame('approved', $this->addendum1->fresh()->status);
        $this->assertSame('approved', $this->addendum3->fresh()->status);
        $this->assertSame('draft', $this->addendum2->fresh()->status);

        // Sources are read, not rewritten — their own totals/kind are untouched.
        $this->assertSame('20000.00', $this->original->fresh()->grand_total);
        $this->assertSame(Estimate::KIND_STANDALONE, $this->original->fresh()->kind);
        $this->assertNull($this->original->fresh()->job_id);
        $this->assertNull($this->addendum1->fresh()->job_id);
        $this->assertNull($this->addendum3->fresh()->job_id);

        // 6. A brand-new, independent job — not a reuse of any other.
        $this->assertSame(1, Job::count());

        // 5. Billing bills exactly the Selected Total: the invoice raised
        // against this job, from its own (only) estimate, comes out the same.
        $job->update(['status' => Job::STATUS_COMPLETED]);

        $create = $this->actingAs($this->user)->get("/invoices/create?job={$job->id}");
        $create->assertInertia(fn ($page) => $page
            ->where('preselectedJobId', $job->id)
            ->where('preselectedEstimateId', $merged->id));

        $invoiceResponse = $this->actingAs($this->user)->post('/invoices', [
            'client_id' => $this->client->id,
            'job_id' => $job->id,
            'estimate_id' => $merged->id,
            'invoice_date' => now()->toDateString(),
            'tax_pct' => 0,
        ]);
        $invoiceResponse->assertSessionHasNoErrors();

        $invoice = Invoice::where('job_id', $job->id)->sole();
        $this->assertSame('37084.00', $invoice->total);
        $itemDescriptions = $invoice->items()->pluck('description')->sort()->values()->all();
        $this->assertSame(['Addendum 1 scope', 'Addendum 3 scope', 'Original scope'], $itemDescriptions);
    }

    public function test_a_different_selection_of_just_the_original_and_addendum_two_totals_only_those(): void
    {
        $response = $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum2->id],
            'name' => 'Harborview — Alternate Scope',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);
        $response->assertSessionHasNoErrors();

        $job = Job::where('name', 'Harborview — Alternate Scope')->sole();
        $merged = $job->estimates()->sole();

        $this->assertSame('25000.00', $merged->grand_total);
        $this->assertSame('25000.00', $job->fresh()->budget);
        $this->assertSame(2, $merged->items()->count());

        $this->assertSame('approved', $this->addendum2->fresh()->status);
        // Addenda 1 and 3, not part of this selection, are left exactly as
        // they were — never dragged along, never flipped to Approved.
        $this->assertSame('draft', $this->addendum1->fresh()->status);
        $this->assertSame('draft', $this->addendum3->fresh()->status);
    }

    public function test_a_users_edited_total_after_the_fact_is_what_gets_summed_not_any_original_figure(): void
    {
        // The estimator revises Addendum 1's price after it was first saved —
        // this, not whatever the AI originally produced, is what must count.
        $this->addendum1->items()->sole()->update(['unit_cost' => 15000]);
        $this->addendum1->recalculateTotals();
        $this->assertSame('15000.00', $this->addendum1->fresh()->grand_total);

        $this->actingAs($this->user)->post('/jobs/from-estimates', [
            'estimate_ids' => [$this->original->id, $this->addendum1->id],
            'name' => 'Harborview — Revised Scope',
            'team_id' => $this->team->id,
            'address_ids' => [$this->address->id],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();

        $job = Job::where('name', 'Harborview — Revised Scope')->sole();
        $merged = $job->estimates()->sole();

        // 20000 + 15000 (edited), not 20000 + 10000 (original).
        $this->assertSame('35000.00', $merged->grand_total);
        $this->assertSame('35000.00', $job->fresh()->budget);
    }

    private function estimateWith(float $amount, string $kind, ?Estimate $parent, string $description): Estimate
    {
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'client_id' => $this->client->id,
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
