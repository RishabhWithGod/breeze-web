<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Notifications\EstimateStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Estimate Review and Approval: verify the scope and authorize the estimate for use.
 */
class EstimateReviewTest extends TestCase
{
    use RefreshDatabase;

    private CompanyProfile $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'Project Manager']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->owner->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        // Setup is done, so the dashboard is the dashboard.
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->owner->forceFill(['company_id' => $this->company->id])->save();
        $this->owner = $this->owner->fresh();
    }

    private function colleague(string $role): User
    {
        return User::factory()->create(['role' => $role, 'company_id' => $this->company->id]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(): array
    {
        return [
            ['description' => '2x4 Wood Stud Wall', 'commodity' => 'Drywall & Framing', 'unit' => 'LF',
                'material_qty' => 120, 'material_unit_price' => 1.85, 'labor_hours' => 18, 'labor_rate' => 75, 'markup_pct' => 15],
            ['description' => '5/8" GWB Installation', 'commodity' => 'Drywall', 'unit' => 'SF',
                'material_qty' => 480, 'material_unit_price' => 0.62, 'labor_hours' => 40, 'labor_rate' => 68, 'markup_pct' => 15],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(?array $lines = null, array $extra = []): array
    {
        return [
            'lines' => $lines ?? $this->lines(),
            'settings' => ['tax_pct' => 8.25, 'markup_pct' => 15, 'labor_rate' => 65, 'scope_of_work' => 'Furnish all labor and materials for the electrical scope.'],
            ...$extra,
        ];
    }

    /** An estimate built and sent for approval by the owner. */
    private function sentEstimate(?string $note = null): Estimate
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'Plant Expansion Phase 1', 'client' => 'Acme Manufacturing', 'status' => 'draft']);
        $this->actingAs($this->owner)->post(route('estimate-builder.store'), ['project_id' => $project->id]);
        $estimate = Estimate::where('builder_managed', true)->latest('id')->firstOrFail();

        $this->actingAs($this->owner)
            ->post(route('estimate-builder.request-approval', $estimate), $this->payload(extra: ['revision_note' => $note]))
            ->assertRedirect(route('estimates.review', $estimate));

        return $estimate->fresh();
    }

    public function test_sending_an_estimate_writes_its_first_revision_and_shows_the_review(): void
    {
        $estimate = $this->sentEstimate();

        $revision = $estimate->revisions()->sole();
        $this->assertSame(1, $revision->version);
        $this->assertSame('Initial estimate', $revision->changes);
        $this->assertSame($this->owner->id, $revision->user_id);
        $this->assertEquals(5713.48, $revision->total);
        $this->assertSame(2, $revision->item_count);

        $this->actingAs($this->owner)->get(route('estimates.review', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->component('EstimateReview')
                ->where('estimate.status', 'sent')
                ->where('estimate.number', $estimate->number)
                ->where('client.name', 'Acme Manufacturing')
                ->where('project.name', 'Plant Expansion Phase 1')
                ->where('scopeOfWork', 'Furnish all labor and materials for the electrical scope.')
                ->has('exclusions', 7)
                ->where('exclusions.0', 'Low voltage systems (data, AV, security)')
                ->where('canDecide', true)
                ->where('approval', null)
                ->where('exports', null)
                ->where('jobUrl', null)
                ->has('revisions', 1)
                ->where('revisions.0.version', 1)
                ->where('revisions.0.changes', 'Initial estimate')
                ->where('revisions.0.by', $this->owner->name)
                // By commodity, each row's own markup in it; tax is a line of its own.
                ->where('summary.rows.0.category', 'Drywall & Framing')
                ->where('summary.rows.0.amount', 1807.8)
                ->where('summary.rows.1.category', 'Drywall')
                ->where('summary.rows.1.amount', 3470.24)
                ->where('summary.rows.2.category', 'Tax')
                ->where('summary.rows.2.amount', 435.44)
                ->where('summary.total', 5713.48)
                ->where('summary.cost', 4589.6)
                ->where('summary.markup', 688.44)
                ->where('summary.rows.0.pct', 31.6));
    }

    public function test_a_revision_note_is_what_the_history_says(): void
    {
        $estimate = $this->sentEstimate('Priced from the Sept takeoff');

        $this->assertSame('Priced from the Sept takeoff', $estimate->revisions()->sole()->changes);
    }

    public function test_approving_locks_the_revision_and_records_who_and_when(): void
    {
        Notification::fake();
        $estimate = $this->sentEstimate();
        $reviewer = $this->colleague('Estimator');

        $this->actingAs($reviewer)->post(route('estimates.approve', $estimate), ['notes' => 'Scope checked against the drawings.'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $estimate->refresh();
        $this->assertSame('approved', $estimate->status);
        $this->assertSame($reviewer->id, $estimate->approved_by);
        $this->assertNotNull($estimate->approved_at);
        $this->assertSame(1, $estimate->approved_revision);
        $this->assertSame('Scope checked against the drawings.', $estimate->review_notes);
        Notification::assertSentTo($this->owner, EstimateStatusChanged::class, fn ($n) => $n->status === 'approved');

        // The approved revision is locked: neither the builder nor the ordinary editor can change it.
        $this->actingAs($this->owner)->put(route('estimate-builder.save', $estimate), $this->payload())->assertStatus(409);
        $this->actingAs($this->owner)->post(route('estimates.items.store', $estimate), [
            'category' => 'material', 'description' => 'Sneaky', 'unit' => 'EA', 'quantity' => 1, 'unit_cost' => 1,
        ])->assertStatus(409);
        $this->actingAs($this->owner)->put(route('estimates.update', $estimate), ['status' => 'draft'])->assertStatus(409);
        $this->assertSame('approved', $estimate->fresh()->status);

        // What is shown now: who approved, the way on to a job, and the files.
        $this->actingAs($this->owner)->get(route('estimates.review', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->where('estimate.status', 'approved')
                ->where('canDecide', false)
                ->where('approval.by', $reviewer->name)
                ->where('approval.revision', 1)
                ->where('approval.notes', 'Scope checked against the drawings.')
                ->where('revisions.0.approved', true)
                ->where('exports.pdf', "/estimates/{$estimate->id}/pdf")
                ->where('exports.csv', "/estimates/{$estimate->id}/export/csv")
                ->where('jobUrl', "/estimates/addenda?project={$estimate->project_id}")
                ->where('hasJob', false));

        // A decision is made once.
        $this->actingAs($reviewer)->post(route('estimates.approve', $estimate))->assertStatus(409);
        $this->actingAs($reviewer)->post(route('estimates.return', $estimate), ['notes' => 'Too late'])->assertStatus(409);
    }

    public function test_returning_it_for_edits_needs_notes_and_reopens_the_worksheet(): void
    {
        Notification::fake();
        $estimate = $this->sentEstimate();
        $reviewer = $this->colleague('Supervisor');

        $this->actingAs($reviewer)->post(route('estimates.return', $estimate), ['notes' => ''])->assertSessionHasErrors('notes');
        $this->assertSame('sent', $estimate->fresh()->status);

        $this->actingAs($reviewer)->post(route('estimates.return', $estimate), ['notes' => 'Labor hours look low on the framing.'])
            ->assertRedirect(route('estimates.index'));

        $estimate->refresh();
        $this->assertSame('draft', $estimate->status);
        $this->assertSame('Labor hours look low on the framing.', $estimate->review_notes);
        $this->assertSame($reviewer->id, $estimate->reviewed_by);
        $this->assertNull($estimate->approved_by);
        Notification::assertSentTo($this->owner, EstimateStatusChanged::class, fn ($n) => $n->status === 'returned');

        // The builder says why, and can be edited again.
        $this->actingAs($this->owner)->get(route('estimate-builder.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->where('estimate.locked', false)
                ->where('estimate.returned.notes', 'Labor hours look low on the framing.')
                ->where('estimate.returned.by', $reviewer->name));

        // Fix it and send it again: the next version says what changed, and the old notes are answered.
        $lines = $this->lines();
        $lines[0]['labor_hours'] = 30;
        $this->actingAs($this->owner)->post(route('estimate-builder.request-approval', $estimate), $this->payload($lines))
            ->assertRedirect(route('estimates.review', $estimate));

        $estimate->refresh();
        $this->assertSame('sent', $estimate->status);
        $this->assertNull($estimate->review_notes);
        $revisions = $estimate->revisions()->get();
        $this->assertSame([2, 1], $revisions->pluck('version')->all());
        $this->assertStringStartsWith('Total $5,713.48 → $', $revisions[0]->changes);

        $this->actingAs($this->owner)->get(route('estimates.review', $estimate))
            ->assertInertia(fn (Assert $page) => $page->has('revisions', 2)->where('revisions.0.version', 2));

        // Approving it now approves the latest revision.
        $this->actingAs($reviewer)->post(route('estimates.approve', $estimate))->assertSessionHasNoErrors();
        $this->assertSame(2, $estimate->fresh()->approved_revision);
    }

    public function test_a_scope_of_work_is_needed_before_it_can_be_sent(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'P', 'client' => 'C', 'status' => 'draft']);
        $this->actingAs($this->owner)->post(route('estimate-builder.store'), ['project_id' => $project->id]);
        $estimate = Estimate::latest('id')->firstOrFail();

        $payload = $this->payload();
        $payload['settings']['scope_of_work'] = '   ';

        $this->actingAs($this->owner)->post(route('estimate-builder.request-approval', $estimate), $payload)->assertSessionHasErrors('scope_of_work');
        $this->assertSame('draft', $estimate->fresh()->status);
        $this->assertSame(0, $estimate->revisions()->count());
    }

    public function test_scope_and_exclusions_are_kept_from_the_builder(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'P', 'client' => 'C', 'status' => 'draft']);
        $this->actingAs($this->owner)->post(route('estimate-builder.store'), ['project_id' => $project->id]);
        $estimate = Estimate::latest('id')->firstOrFail();

        // A new estimate starts with the usual exclusions, which are its own to edit.
        $this->assertSame(config('estimates.default_exclusions'), $estimate->exclusions);

        $payload = $this->payload();
        $payload['settings']['exclusions'] = ['  No trenching ', '', 'Permits by owner'];
        $this->actingAs($this->owner)->put(route('estimate-builder.save', $estimate), $payload)->assertSessionHasNoErrors();

        $estimate->refresh();
        $this->assertSame(['No trenching', 'Permits by owner'], $estimate->exclusions);
        $this->assertSame('Furnish all labor and materials for the electrical scope.', $estimate->scope_of_work);
    }

    public function test_only_reviewers_of_the_company_decide_and_a_foreman_only_reads_an_approved_one(): void
    {
        $estimate = $this->sentEstimate();

        foreach (['Project Manager', 'Estimator', 'Supervisor', 'Admin', 'Owner'] as $role) {
            $user = $this->colleague($role);
            $this->actingAs($user)->get(route('estimates.review', $estimate))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('canDecide', true));
        }

        // A foreman cannot see it while it is pending, or decide it.
        $foreman = $this->colleague('Foreman');
        $this->actingAs($foreman)->get(route('estimates.review', $estimate))->assertForbidden();
        $this->actingAs($foreman)->post(route('estimates.approve', $estimate))->assertForbidden();

        foreach (['Journeyman', 'Apprentice'] as $role) {
            $user = $this->colleague($role);
            $this->actingAs($user)->get(route('estimates.review', $estimate))->assertForbidden();
            $this->actingAs($user)->post(route('estimates.approve', $estimate))->assertForbidden();
        }

        // Another company's estimator cannot see it at all.
        $rivalOwner = User::factory()->create(['role' => 'Project Manager']);
        $rival = CompanyProfile::create([
            'user_id' => $rivalOwner->id, 'name' => 'Rival', 'business_address' => '1', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'r@x.test', 'timezone' => 'America/Chicago',
        ]);
        $rivalOwner->forceFill(['company_id' => $rival->id])->save();
        $this->actingAs($rivalOwner->fresh())->get(route('estimates.review', $estimate))->assertForbidden();
        $this->actingAs($rivalOwner->fresh())->post(route('estimates.approve', $estimate))->assertForbidden();
        $this->assertSame('sent', $estimate->fresh()->status);

        // Once approved, the foreman may read it — but still not decide anything.
        $this->actingAs($this->colleague('Estimator'))->post(route('estimates.approve', $estimate));
        $this->actingAs($foreman)->get(route('estimates.review', $estimate))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('estimate.status', 'approved')->where('canDecide', false));
    }

    public function test_a_draft_has_nothing_to_review(): void
    {
        $project = Project::create(['user_id' => $this->owner->id, 'name' => 'P', 'client' => 'C', 'status' => 'draft']);
        $this->actingAs($this->owner)->post(route('estimate-builder.store'), ['project_id' => $project->id]);
        $estimate = Estimate::latest('id')->firstOrFail();

        $this->actingAs($this->owner)->get(route('estimates.review', $estimate))->assertRedirect(route('estimate-builder.show', $estimate));
        $this->actingAs($this->owner)->post(route('estimates.approve', $estimate))->assertStatus(409);
    }

    public function test_a_builder_estimate_that_is_waiting_opens_on_the_review(): void
    {
        $estimate = $this->sentEstimate();

        $this->actingAs($this->owner)->get(route('estimates.show', $estimate))->assertRedirect(route('estimates.review', $estimate));
    }

    public function test_an_estimate_made_another_way_that_is_waiting_can_be_reviewed_too(): void
    {
        $estimate = Estimate::create([
            'user_id' => $this->owner->id, 'number' => 'EST-5001', 'client' => 'Acme', 'project' => 'Old style', 'issued_on' => '2026-01-01',
            'amount' => 0, 'status' => 'sent', 'kind' => 'standalone', 'markup_pct' => 10, 'tax_pct' => 5,
        ]);
        $estimate->items()->create(['category' => 'material', 'description' => 'A', 'unit' => 'EA', 'quantity' => 10, 'unit_cost' => 10, 'position' => 1]);
        $estimate->items()->create(['category' => 'labor', 'description' => 'B', 'unit' => 'HR', 'quantity' => 5, 'unit_cost' => 20, 'position' => 2]);
        $estimate->recalculateTotals();

        $this->actingAs($this->owner)->get(route('estimates.review', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->where('estimate.builderManaged', false)
                ->has('revisions', 0)
                ->where('summary.rows.0.category', 'Labor')
                ->where('summary.rows.1.category', 'Materials')
                ->where('summary.rows.2.category', 'Markup')
                ->where('summary.rows.3.category', 'Tax')
                ->where('summary.total', 231));

        $this->actingAs($this->owner)->post(route('estimates.approve', $estimate))->assertSessionHasNoErrors();
        $this->assertSame('approved', $estimate->fresh()->status);
        $this->assertNull($estimate->fresh()->approved_revision);
    }

    public function test_the_dashboard_stops_counting_it_once_it_is_decided(): void
    {
        $estimate = $this->sentEstimate();
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->where('summary.2.value', 1));

        $this->actingAs($this->owner)->post(route('estimates.approve', $estimate));
        $this->actingAs($this->owner)->get('/home')->assertInertia(fn (Assert $page) => $page->where('summary.2.value', 0));
    }
}
