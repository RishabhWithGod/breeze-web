<?php

namespace Tests\Feature;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Once a user edits an estimate, the saved figure is the total everywhere —
 * never the original AI-generated number. `Estimate::recalculateTotals()` is
 * already the one place a total is ever written (confirmed by inspection, not
 * just asserted here); the one real gap was `Job.budget`, which only ever
 * tracked an estimate at takeoff time and never after — see the
 * `EstimateBuilder::syncJobBudget()` calls added to `EstimateDetailController`.
 */
class EstimateTotalSourceOfTruthTest extends TestCase
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
            'status' => 'draft',
        ]);
    }

    public function test_a_users_edited_total_overrides_the_ai_generated_total_everywhere(): void
    {
        // "AI generated total = $0" — a standalone estimate with no priced lines yet.
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'number' => 'EST-9001',
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0,
        ]);

        $this->assertSame('0.00', $estimate->refresh()->grand_total);

        $this->actingAs($this->user)
            ->post("/estimates/{$estimate->id}/items", [
                'category' => 'material',
                'description' => 'Panelboard',
                'unit' => 'ea',
                'quantity' => 1,
                'unit_cost' => 500,
            ])
            ->assertSessionHasNoErrors();

        $estimate->refresh();
        $this->assertSame('500.00', $estimate->grand_total);
        $this->assertSame('500.00', $estimate->amount);

        // Estimate Show — a fresh render, not a cached client value.
        $this->actingAs($this->user)
            ->get("/estimates/{$estimate->id}")
            ->assertInertia(fn (Assert $page) => $page->where('totals.grandTotal', 500));

        // Dashboard's draft-estimates card reads the same persisted column.
        $this->actingAs($this->user)
            ->get('/home')
            ->assertInertia(fn (Assert $page) => $page
                ->where('draftEstimates', fn ($rows) => collect($rows)->firstWhere('id', $estimate->id)['amount'] === 500));
    }

    public function test_editing_an_estimate_after_its_job_already_exists_resyncs_the_jobs_budget(): void
    {
        $job = Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'client_id' => $this->project->client_id,
            'client' => 'Harborview Data Hall',
            'name' => 'Harborview — Phase 1',
            'status' => 'planning',
        ]);

        $estimate = Estimate::create([
            'job_id' => $job->id,
            'project_id' => $this->project->id,
            'number' => 'EST-9002',
            'client' => 'Harborview Data Hall',
            'project' => 'Harborview Data Hall',
            'issued_on' => now()->toDateString(),
            'status' => Estimate::STATUS_FOR_A_LIVE_JOB,
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0,
        ]);

        $item = $estimate->items()->create([
            'category' => 'material',
            'description' => 'Panelboard',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 200,
            'position' => 1,
        ]);
        $estimate->recalculateTotals();

        // Nothing has synced the job's budget yet — it only ever moved at
        // takeoff time, never from a manual edit, until the fix below.
        $this->assertNull($job->fresh()->budget);

        $this->actingAs($this->user)
            ->put("/estimates/{$estimate->id}/items/{$item->id}", [
                'category' => 'material',
                'description' => 'Panelboard',
                'unit' => 'ea',
                'quantity' => 1,
                'unit_cost' => 500,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('500.00', $estimate->fresh()->grand_total);
        $this->assertSame('500.00', $job->fresh()->budget);

        // Job Show reads the same, freshly-synced column.
        $this->actingAs($this->user)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page->where('job.budget', 500));
    }
}
