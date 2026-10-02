<?php

namespace Tests\Feature;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\JobFieldMaterial;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The web Job Detail page's planned-vs-actual materials data, written by the
 * mobile "Material and Work Changes" screen.
 */
class JobShowFieldMaterialsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Job, 2: JobTask} */
    private function makeJobWithTask(): array
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $client = $user->clients()->create(['name' => 'Harborview']);
        $project = Project::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);
        $site = $client->addresses()->create([
            'label' => 'Tower',
            'address' => '1 Harbor Way',
            'is_primary' => true,
            'position' => 0,
        ]);
        $job = Job::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'project_id' => $project->id,
            'name' => 'Riser rewire',
            'client' => 'Harborview',
            'status' => 'in-progress',
        ]);
        $job->addresses()->sync([$site->id => ['position' => 0]]);

        $schedule = app(ScheduleBuilder::class)->build($job, $user, withTasks: true);

        return [$user, $job, $schedule->tasks()->first()];
    }

    private function makeLine(JobTask $task, string $description, float $qty, string $category = EstimateItem::CATEGORY_MATERIAL): EstimateItem
    {
        $estimate = Estimate::create([
            'number' => 'EST-'.random_int(1000, 9999),
            'client' => 'Test Client',
            'project' => 'Test Project',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);

        return EstimateItem::create([
            'estimate_id' => $estimate->id,
            'job_task_id' => $task->id,
            'category' => $category,
            'description' => $description,
            'unit' => 'ea',
            'quantity' => $qty,
            'unit_cost' => 10,
        ]);
    }

    public function test_over_plan_and_added_are_flagged_and_under_plan_is_not(): void
    {
        [$user, $job, $task] = $this->makeJobWithTask();
        $over = $this->makeLine($task, 'Conduit', 10);
        $under = $this->makeLine($task, 'Wire nuts', 50);
        $this->makeLine($task, 'Unreported box', 3);
        $this->makeLine($task, 'Install labor', 8, EstimateItem::CATEGORY_LABOR);

        JobFieldMaterial::create([
            'job_id' => $job->id, 'job_task_id' => $task->id, 'estimate_item_id' => $over->id,
            'description' => 'Conduit', 'unit' => 'ea', 'actual_quantity' => 14,
            'reason' => 'Longer run', 'user_id' => $user->id,
        ]);
        JobFieldMaterial::create([
            'job_id' => $job->id, 'job_task_id' => $task->id, 'estimate_item_id' => $under->id,
            'description' => 'Wire nuts', 'unit' => 'ea', 'actual_quantity' => 40,
            'user_id' => $user->id,
        ]);
        JobFieldMaterial::create([
            'job_id' => $job->id, 'job_task_id' => $task->id, 'client_key' => 'abc',
            'description' => 'Extra breaker', 'unit' => 'ea', 'actual_quantity' => 2,
            'reason' => 'Panel full', 'user_id' => $user->id,
        ]);

        $this->actingAs($user)->get(route('jobs.show', $job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobShow')
                ->has('fieldMaterials.planned', 3)
                ->where('fieldMaterials.planned.0.description', 'Conduit')
                ->where('fieldMaterials.planned.0.plannedQty', 10)
                ->where('fieldMaterials.planned.0.actualQty', 14)
                ->where('fieldMaterials.planned.0.exception', 'over')
                ->where('fieldMaterials.planned.0.reason', 'Longer run')
                ->where('fieldMaterials.planned.0.reportedBy', $user->name)
                ->where('fieldMaterials.planned.1.exception', 'under')
                ->where('fieldMaterials.planned.2.actualQty', null)
                ->where('fieldMaterials.planned.2.exception', null)
                ->has('fieldMaterials.added', 1)
                ->where('fieldMaterials.added.0.description', 'Extra breaker')
                ->where('fieldMaterials.added.0.qty', 2)
                ->where('fieldMaterials.added.0.reason', 'Panel full')
                ->where('fieldMaterials.needsReviewCount', 2)
            );
    }

    public function test_a_job_with_no_field_data_has_an_empty_summary(): void
    {
        [$user, $job] = $this->makeJobWithTask();

        $this->actingAs($user)->get(route('jobs.show', $job))
            ->assertInertia(fn (Assert $page) => $page
                ->where('fieldMaterials.planned', [])
                ->where('fieldMaterials.added', [])
                ->where('fieldMaterials.needsReviewCount', 0)
            );
    }

    public function test_a_manager_edits_then_approves_an_added_entry_onto_the_estimate(): void
    {
        [$user, $job, $task] = $this->makeJobWithTask();
        $estimate = Estimate::create([
            'job_id' => $job->id, 'number' => 'EST-7001', 'client' => 'Harborview', 'project' => 'Data Hall',
            'issued_on' => now()->toDateString(), 'amount' => 0, 'status' => 'approved', 'kind' => 'standalone',
        ]);
        $entry = JobFieldMaterial::create([
            'job_id' => $job->id, 'job_task_id' => $task->id, 'kind' => 'labor', 'client_key' => 'k1',
            'description' => 'Panel install', 'unit' => 'hr', 'actual_quantity' => 6, 'unit_price' => 0, 'total' => 0,
            'user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->put("/jobs/{$job->id}/field-materials/{$entry->id}", [
                'kind' => 'labor', 'description' => 'Panel install', 'quantity' => 6, 'unit_price' => 85,
            ])->assertRedirect();
        $this->assertSame('510.00', $entry->fresh()->total);

        $this->actingAs($user)->post("/jobs/{$job->id}/field-materials/{$entry->id}/approve")->assertRedirect();

        $entry->refresh();
        $this->assertSame(JobFieldMaterial::STATUS_APPROVED, $entry->status);
        $item = EstimateItem::findOrFail($entry->added_estimate_item_id);
        $this->assertSame($estimate->id, $item->estimate_id);
        $this->assertSame(EstimateItem::CATEGORY_LABOR, $item->category);
        $this->assertSame('510.00', $item->total);
        $this->assertEquals(510.0, (float) $estimate->fresh()->labor_total);

        // Approved entries are on the estimate and are not changed again here.
        $this->actingAs($user)->delete("/jobs/{$job->id}/field-materials/{$entry->id}")->assertStatus(409);
    }
}
