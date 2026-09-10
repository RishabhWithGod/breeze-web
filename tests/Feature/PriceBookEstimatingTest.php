<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\EstimateItem;
use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\User;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\PriceBookLookup;
use App\Services\Takeoff\SymbolCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An estimate priced from the company's own bids rather than from constants.
 *
 * The point of the price book is that the number on the estimate is a number
 * somebody has charged. These tests hold that line: the rate, the hours, the
 * wording, and — where the price book has never seen an item — an honest mark
 * saying the figure is a stand-in.
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

        $this->seedPriceBook();
    }

    /**
     * The drawing has room for a tag; the schedule has the thing. The estimate
     * should read like the schedule.
     */
    public function test_a_schedule_tag_is_priced_and_named_from_the_price_book(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'EM2',
            'count' => 4,
            'confidence' => 0.9,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);

        $material = $estimate->items()->where('category', '!=', EstimateItem::CATEGORY_LABOR)->sole();

        $this->assertSame('EM2, NEW BATTERY 2/HEAD EM FIXTURE', $material->description);
        $this->assertSame('60.0000', $material->unit_cost);
        $this->assertSame('price-book', $material->pricing_source);
        $this->assertSame('tag', $material->pricing_confidence);
        $this->assertNotNull($material->price_book_item_id);
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
        $labor = $estimate->items()->where('category', EstimateItem::CATEGORY_LABOR)->sole();

        // 1.25 hours a fixture, four of them.
        $this->assertSame('5.0000', $labor->quantity);
        $this->assertSame('48.0000', $labor->unit_cost);
        $this->assertStringContainsString('EM2, NEW BATTERY', $labor->description);
    }

    /**
     * A guess must never read like a quote. Anything the price book has not
     * seen keeps the config catalog's figure and is marked as such.
     */
    public function test_an_item_the_price_book_has_never_seen_is_marked_as_a_stand_in(): void
    {
        $this->result->finalSymbols()->create([
            'project_id' => $this->projectId,
            'name' => 'Triangular Device',
            'count' => 2,
            'confidence' => 0.5,
        ]);

        $estimate = app(EstimateBuilder::class)->fromFinalJson($this->result, $this->user);
        $material = $estimate->items()->where('category', '!=', EstimateItem::CATEGORY_LABOR)->sole();

        $this->assertSame('catalog', $material->pricing_source);
        $this->assertNull($material->price_book_item_id);
        // Its own name, because the catalog has no fuller wording to offer.
        $this->assertSame('Triangular Device', $material->description);
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
        $rates = app(SymbolCatalog::class)->for('3/4" CONDUIT - EMT');

        $this->assertSame('price-book', $rates['source']);
        $this->assertSame(0.8296, $rates['unit_cost']);
        $this->assertSame('ft', $rates['unit']);
    }

    /** The rate quoted is the one these jobs usually paid, not the outlier. */
    public function test_the_quoted_rate_is_the_median_of_what_was_charged(): void
    {
        $this->assertSame(48.0, app(PriceBookLookup::class)->laborRate());
    }

    private function seedPriceBook(): void
    {
        $import = PriceBookImport::create([
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
            PriceBookLine::create([
                'price_book_import_id' => $import->id,
                'section' => $section,
                'description' => $description,
                'quantity' => 10,
                'unit' => $unit,
                'unit_material_cost' => $cost,
                // What the workbooks charge an hour, and what the estimate's
                // labour lines are expected to pick up.
                'manhour_rate' => 48,
                'unit_manhours' => $hours,
                'match_key' => PriceBookLine::keyFor($description),
            ]);

            PriceBookItem::create([
                'match_key' => PriceBookLine::keyFor($description),
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
