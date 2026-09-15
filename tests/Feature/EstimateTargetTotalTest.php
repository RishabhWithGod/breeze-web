<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\EstimateItem;
use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use App\Models\User;
use App\Services\Estimating\WorkbookReader;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A project opened with a budget gets an estimate raised at exactly that
 * budget, whatever the underlying line items would otherwise have summed to.
 */
class EstimateTargetTotalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_estimate_lands_exactly_on_the_projects_budget(): void
    {
        $user = User::factory()->create();

        $project = $user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
            'estimate_target_total' => 5000,
        ]);

        $import = ProjectRateImport::create([
            'project_id' => $project->id,
            'file_name' => 'Electrical Estimate - TEST.xlsx',
            'file_hash' => str_repeat('c', 64),
            'project_name' => 'TEST PROJECT',
            'material_tax_pct' => 7.5,
            'overhead_pct' => 10,
            'profit_pct' => 12,
            'line_count' => 2,
            'imported_at' => now(),
        ]);

        foreach ([
            ['EM2, NEW BATTERY 2/HEAD EM FIXTURE', 'EA', 60.0, 1.25, 'LIGHTING FIXTURES'],
            ['3/4" CONDUIT - EMT', 'FT', 0.8296, 0.062, 'BRANCH WIRING'],
        ] as [$description, $unit, $cost, $hours, $section]) {
            ProjectRateLine::create([
                'project_rate_import_id' => $import->id,
                'project_id' => $project->id,
                'section' => $section,
                'description' => $description,
                'quantity' => 10,
                'unit' => $unit,
                'unit_material_cost' => $cost,
                'manhour_rate' => 48,
                'unit_manhours' => $hours,
                'match_key' => WorkbookReader::keyFor($description),
            ]);

            ProjectRateItem::create([
                'project_id' => $project->id,
                'match_key' => WorkbookReader::keyFor($description),
                'unit' => $unit,
                'description' => $description,
                'section' => $section,
                'unit_material_cost' => $cost,
                'unit_manhours' => $hours,
                'sample_count' => 1,
                'min_material_cost' => $cost,
                'max_material_cost' => $cost,
                'last_seen_at' => now(),
            ]);
        }

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);

        $result->finalSymbols()->create([
            'project_id' => $project->id,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);
        $result->finalSymbols()->create([
            'project_id' => $project->id,
            'name' => '3/4" CONDUIT - EMT',
            'count' => 120,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $user);

        $this->assertSame('5000.00', $estimate->grand_total);
        $this->assertGreaterThan(1, $estimate->items()->count());

        // The relative weight of each priced line is preserved: the fixture
        // still costs meaningfully more than a single foot of conduit.
        $fixture = $estimate->items()->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')->sole();
        $conduit = $estimate->items()->where('category', EstimateItem::CATEGORY_MATERIAL)
            ->where('description', '!=', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')->sole();

        $this->assertGreaterThan((float) $conduit->unit_cost, (float) $fixture->unit_cost);
    }

    public function test_without_a_budget_the_estimate_keeps_its_natural_total(): void
    {
        $user = User::factory()->create();

        $project = $user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);

        $this->assertNull($project->estimate_target_total);

        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);

        $result->finalSymbols()->create([
            'project_id' => $project->id,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($result, $user);

        // No target to scale to, and no rate list uploaded: the line prices
        // at zero rather than at the budget figure from the other test.
        $this->assertSame('0.00', $estimate->grand_total);
    }
}
