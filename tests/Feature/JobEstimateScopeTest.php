<?php

namespace Tests\Feature;

use App\Models\ChangeOrder;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use App\Services\JobCosting\JobCostSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estimated is the estimate the job was raised on; whatever changes after
 * that moves the job's current scope; completing the job freezes that scope
 * as the final one, which is what counts as actual.
 */
class JobEstimateScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_the_estimate_a_job_is_raised_on_is_its_baseline(): void
    {
        $job = $this->job();
        $this->estimate($job, material: 2000, labor: 3000, equipment: 500);

        $baseline = $job->fresh()->estimate_baseline;

        $this->assertSame(2000.0, (float) $baseline['material']);
        $this->assertSame(500.0, (float) $baseline['equipment']);
        $this->assertSame(5500.0, (float) $baseline['total']);
    }

    public function test_later_estimate_changes_move_actual_but_not_estimated(): void
    {
        $job = $this->job();
        $estimate = $this->estimate($job, material: 2000, labor: 3000, equipment: 500);

        $estimate->update(['material_total' => 2600, 'equipment_total' => 700, 'grand_total' => 6800]);

        $row = app(JobCostSummary::class)->for($job->fresh());

        $this->assertSame(2000.0, $row['estimatedMaterialCost']);
        $this->assertSame(2600.0, $row['actualMaterialCost']);
        $this->assertSame(500.0, $row['estimatedEquipmentCost']);
        $this->assertSame(700.0, $row['actualEquipmentCost']);
        $this->assertSame(600.0, $row['materialCostVariance']);
        $this->assertSame(6800.0, $row['revenue']);
        $this->assertSame(1300.0, $row['scopeChange']);
        $this->assertSame(5500.0, $row['baselineTotal']);
        $this->assertFalse($row['isFinal']);
    }

    public function test_an_approved_change_order_adds_to_the_current_scope_but_a_pending_one_does_not(): void
    {
        $job = $this->job();
        $this->estimate($job, material: 2000, labor: 3000, equipment: 0);

        $this->changeOrder($job, ChangeOrder::STATUS_APPROVED, material: 300, labor: 150, hours: 2, sell: 600);
        $this->changeOrder($job, ChangeOrder::STATUS_SUBMITTED, material: 9999, labor: 9999, hours: 99, sell: 9999);

        $row = app(JobCostSummary::class)->for($job->fresh());

        $this->assertSame(2000.0, $row['estimatedMaterialCost']);
        $this->assertSame(2300.0, $row['actualMaterialCost']);
        $this->assertSame(5600.0, $row['revenue']);
        $this->assertSame(600.0, $row['scopeChange']);
    }

    public function test_completing_a_job_freezes_its_final_estimate_as_actual(): void
    {
        $job = $this->job();
        $estimate = $this->estimate($job, material: 2000, labor: 3000, equipment: 0);
        $estimate->update(['material_total' => 2400, 'grand_total' => 5400]);

        $job->update(['status' => Job::STATUS_COMPLETED]);
        $this->assertSame(2400.0, (float) $job->fresh()->estimate_final['material']);

        // Whatever happens to the estimate afterwards, the completed job's actual is the one it finished on.
        $estimate->update(['material_total' => 9000, 'grand_total' => 12000]);

        $row = app(JobCostSummary::class)->for($job->fresh());
        $this->assertTrue($row['isFinal']);
        $this->assertSame(2000.0, $row['estimatedMaterialCost']);
        $this->assertSame(2400.0, $row['actualMaterialCost']);
        $this->assertSame(5400.0, $row['revenue']);
    }

    public function test_reopening_a_completed_job_makes_its_scope_live_again(): void
    {
        $job = $this->job();
        $estimate = $this->estimate($job, material: 2000, labor: 3000, equipment: 0);
        $job->update(['status' => Job::STATUS_COMPLETED]);
        $job->update(['status' => 'in-progress']);

        $this->assertNull($job->fresh()->estimate_final);

        $estimate->update(['material_total' => 2500, 'grand_total' => 5500]);
        $this->assertSame(2500.0, app(JobCostSummary::class)->for($job->fresh())['actualMaterialCost']);
    }

    public function test_an_estimate_still_unpriced_becomes_the_baseline_when_it_is_first_priced(): void
    {
        $job = $this->job();
        $estimate = $this->estimate($job, material: 0, labor: 0, equipment: 0, grand: 0);
        $this->assertNull($job->fresh()->estimate_baseline);

        $estimate->update(['material_total' => 1000, 'grand_total' => 1000]);
        $this->assertSame(1000.0, (float) $job->fresh()->estimate_baseline['total']);

        // Priced again later: the baseline stays where it first landed.
        $estimate->update(['material_total' => 1800, 'grand_total' => 1800]);
        $this->assertSame(1000.0, (float) $job->fresh()->estimate_baseline['total']);
    }

    public function test_a_job_with_no_baseline_is_measured_against_its_current_scope(): void
    {
        $job = $this->job();

        $this->assertSame(0.0, app(JobCostSummary::class)->for($job)['scopeChange']);
        $this->assertFalse(app(JobCostSummary::class)->for($job)['hasBaseline']);
    }

    public function test_the_batched_path_agrees_with_the_single_job_path(): void
    {
        $job = $this->job();
        $estimate = $this->estimate($job, material: 2000, labor: 3000, equipment: 500);
        $estimate->update(['material_total' => 2300, 'grand_total' => 6300]);
        $this->changeOrder($job, ChangeOrder::STATUS_APPROVED, material: 100, labor: 50, hours: 1, sell: 250);

        $service = app(JobCostSummary::class);
        $this->assertEquals(
            $service->for($job->fresh()),
            app(JobCostSummary::class)->forMany(collect([$job->fresh()]))->first(),
        );
    }

    public function test_the_scope_columns_are_hidden_from_a_role_without_cost_access(): void
    {
        $job = $this->job();
        $this->estimate($job, material: 2000, labor: 3000, equipment: 0);

        $row = JobCostSummary::redact(app(JobCostSummary::class)->for($job->fresh()));

        $this->assertNull($row['baselineTotal']);
        $this->assertNull($row['currentScopeTotal']);
        $this->assertNull($row['scopeChange']);
    }

    private function job(): Job
    {
        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside']);
        $project = Project::create(['user_id' => $this->manager->id, 'client_id' => $client->id, 'name' => 'P', 'client' => $client->name, 'status' => 'in-progress']);

        return Job::create([
            'user_id' => $this->manager->id, 'project_id' => $project->id,
            'foreman_id' => (Foreman::first() ?? Foreman::create(['name' => 'Dana', 'initials' => 'D']))->id,
            'name' => 'Riverside', 'client' => $client->name, 'status' => 'in-progress',
        ]);
    }

    private function estimate(Job $job, float $material, float $labor, float $equipment, ?float $grand = null): Estimate
    {
        $subtotal = $material + $labor + $equipment;

        return Estimate::create([
            'job_id' => $job->id, 'number' => 'EST-'.random_int(1000, 9999), 'client' => 'Riverside', 'project' => 'P',
            'issued_on' => now()->toDateString(), 'amount' => $grand ?? $subtotal, 'status' => 'approved',
            'material_total' => $material, 'labor_total' => $labor, 'equipment_total' => $equipment,
            'subtotal' => $subtotal, 'grand_total' => $grand ?? $subtotal,
        ]);
    }

    private function changeOrder(Job $job, string $status, float $material, float $labor, float $hours, float $sell): ChangeOrder
    {
        $co = new ChangeOrder([
            'owner_id' => $this->manager->id, 'job_id' => $job->id,
            'number' => (int) ChangeOrder::max('number') + 1, 'created_by' => $this->manager->id, 'description' => 'Extra',
        ]);
        $co->forceFill([
            'status' => $status, 'material_cost' => $material, 'labor_cost' => $labor,
            'labor_hours' => $hours, 'cost_total' => $material + $labor, 'sell_total' => $sell,
        ])->save();

        return $co;
    }
}
