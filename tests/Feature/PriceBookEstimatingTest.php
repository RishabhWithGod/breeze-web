<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\EstimateItem;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use App\Models\User;
use App\Services\Estimating\ProjectRateBook;
use App\Services\Estimating\WorkbookReader;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\SymbolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An estimate merges the drawing's own symbols with this project's uploaded
 * rate list — never a guess.
 *
 * Three things happen at once when an estimate is built:
 *
 *   - a symbol the AI found that the project's own excel also priced is
 *     quoted at that price, at the AI's quantity;
 *   - a symbol the AI found that the excel never priced falls back to the
 *     estimator's price book, and only once that also has nothing to say is
 *     it priced at zero for the estimator to fill in by hand;
 *   - an excel row nothing on the drawing claimed still appears, priced,
 *     at zero quantity — a vendor bid on it, so it isn't silently dropped.
 */
class PriceBookEstimatingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiResult $result;

    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $project = $this->user->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);
        $aiJob = AiJob::create([
            'project_id' => $project->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $this->result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
            'final_payload' => ['final_counts' => []],
        ]);
        $this->projectId = $project->id;

        $this->seedRateBook();
    }

    /**
     * The drawing has room for a tag; the schedule has the thing. The estimate
     * should read like the schedule.
     */
    public function test_a_schedule_tag_is_priced_and_named_from_the_project_rate_list(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);

        $material = $estimate->items()->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')->sole();

        $this->assertSame('60.0000', $material->unit_cost);
        $this->assertSame('4.0000', $material->quantity);
        $this->assertSame('vendor-rate-list', $material->pricing_source);
        $this->assertSame('tag', $material->pricing_confidence);
        $this->assertNotNull($material->project_rate_item_id);
    }

    /** Labour is hours the estimator booked, at the rate these jobs charged. */
    public function test_labour_is_priced_at_the_hours_and_rate_the_bids_used(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);
        $labor = $estimate->items()
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->where('description', 'like', '%EM2%')
            ->sole();

        // 1.25 hours a fixture, four of them.
        $this->assertSame('5.0000', $labor->quantity);
        $this->assertSame('48.0000', $labor->unit_cost);
        $this->assertStringContainsString('EM2, NEW BATTERY', $labor->description);
    }

    /**
     * An excel row nothing on the drawing claimed does not vanish — a vendor
     * priced it, so it still belongs on the estimate, at zero quantity, for
     * the estimator to put a real count against.
     */
    public function test_an_excel_entry_the_ai_never_found_still_appears_at_zero_quantity(): void
    {
        // Only EM2 is on the drawing; the conduit run from the same workbook
        // never shows up in the AI's symbols at all.
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);
        $leftover = $estimate->items()->where('description', '3/4" CONDUIT - EMT')->sole();

        $this->assertSame('0.0000', $leftover->quantity);
        $this->assertSame('0.8296', $leftover->unit_cost);
        $this->assertSame('vendor-rate-list', $leftover->pricing_source);
        $this->assertNotNull($leftover->project_rate_item_id);
        $this->assertSame('0.00', $leftover->total);
    }

    /**
     * A symbol the excel never priced is not immediately a guess — it falls
     * back to the estimator's own price book first.
     */
    public function test_a_symbol_missing_from_the_excel_falls_back_to_the_users_price_book(): void
    {
        PriceBookItem::create([
            'user_id' => $this->user->id,
            'match_key' => PriceBookLine::keyFor('Light Fixture'),
            'unit' => 'ea',
            'description' => 'Light Fixture',
            'unit_material_cost' => 42.0,
            'unit_manhours' => 0.5,
            'sample_count' => 1,
        ]);

        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'Light Fixture',
            'count' => 3,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);
        $material = $estimate->items()
            ->where('category', '!=', EstimateItem::CATEGORY_LABOR)
            ->where('description', 'Light Fixture')
            ->sole();

        $this->assertSame('42.0000', $material->unit_cost);
        $this->assertSame('price-book', $material->pricing_source);
        $this->assertNotNull($material->price_book_item_id);
        $this->assertNull($material->project_rate_item_id);
    }

    /**
     * A guess must never read like a quote. Only once neither the project's
     * own excel nor the price book has ever seen a symbol is it priced at
     * zero, for the estimator to fill in by hand.
     */
    public function test_a_symbol_missing_everywhere_is_priced_at_zero(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'Triangular Device',
            'count' => 2,
            'confidence' => 0.5,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);
        $material = $estimate->items()->where('description', 'Triangular Device')->sole();

        $this->assertSame('unmatched', $material->pricing_source);
        $this->assertSame('0.0000', $material->unit_cost);
        $this->assertNull($material->project_rate_item_id);
        $this->assertNull($material->price_book_item_id);
    }

    /**
     * The header rates come off the bids too — 7.5% tax and 10%/12%
     * compounded, not the 8.25% and nothing that config carried.
     */
    public function test_the_estimate_is_raised_at_the_rates_the_bids_were_priced_at(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 1,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);

        $this->assertSame('7.50', $estimate->tax_pct);
        // 1.10 × 1.12 − 1 = 23.2%, the way a bid sheet stacks them.
        $this->assertSame('23.20', $estimate->markup_pct);
    }

    /**
     * Per-foot rates must survive the trip. Rounded to cents, a conduit run is
     * quoted against a rate the workbook never held.
     */
    public function test_a_per_foot_rate_keeps_its_fractions_of_a_cent(): void
    {
        $rates = app(SymbolCatalog::class)->forProject($this->projectId)->for('3/4" CONDUIT - EMT');

        $this->assertSame('vendor-rate-list', $rates['source']);
        $this->assertSame(0.8296, $rates['unit_cost']);
        $this->assertSame('ft', $rates['unit']);
    }

    /** The rate quoted is the one these jobs usually paid, not the outlier. */
    public function test_the_quoted_rate_is_the_median_of_what_was_charged(): void
    {
        $this->assertSame(48.0, app(ProjectRateBook::class)->forProject($this->projectId)->laborRate());
    }

    private function seedRateBook(): void
    {
        $import = ProjectRateImport::create([
            'project_id' => $this->projectId,
            'file_name' => 'Electrical Estimate - TEST.xlsx',
            'file_hash' => str_repeat('b', 64),
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
                'project_id' => $this->projectId,
                'section' => $section,
                'description' => $description,
                'quantity' => 10,
                'unit' => $unit,
                'unit_material_cost' => $cost,
                // What the workbooks charge an hour, and what the estimate's
                // labour lines are expected to pick up.
                'manhour_rate' => 48,
                'unit_manhours' => $hours,
                'match_key' => WorkbookReader::keyFor($description),
            ]);

            ProjectRateItem::create([
                'project_id' => $this->projectId,
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
    }
}
