<?php

namespace Tests\Feature;

use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PriceBookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_the_price_book_lists_rates_with_the_spread_behind_them(): void
    {
        $this->seedRate();

        $this->actingAs($this->user)
            ->get('/price-book')
            ->assertInertia(fn (Assert $page) => $page
                ->component('PriceBook')
                ->where('totals.items', 1)
                ->where('totals.lines', 2)
                ->has('items.data', 1)
                ->where('items.data.0.description', '3/4" CONDUIT - EMT')
                ->where('items.data.0.unit', 'FT')
                ->where('items.data.0.sampleCount', 2)
                // The jobs disagreed, so both ends are carried through.
                ->where('items.data.0.minMaterialCost', 0.8)
                ->where('items.data.0.maxMaterialCost', 0.9));
    }

    /**
     * A rate has to be traceable to the jobs it came off, or nobody will
     * defend it to a client.
     */
    public function test_an_item_shows_every_line_its_rate_was_worked_out_from(): void
    {
        $item = $this->seedRate();

        $this->actingAs($this->user)
            ->get("/price-book/{$item->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('PriceBookItem')
                ->where('item.description', '3/4" CONDUIT - EMT')
                ->has('lines', 2)
                ->where('lines.0.project', 'DHL PROJECT'));
    }

    public function test_the_search_narrows_the_list(): void
    {
        $this->seedRate();

        $this->actingAs($this->user)
            ->get('/price-book?search=nothing-like-this')
            ->assertInertia(fn (Assert $page) => $page->has('items.data', 0));
    }

    /**
     * The same name priced per foot and per each is two rates, not one
     * disagreeing with itself — so the key alone must not collide.
     */
    public function test_the_match_key_normalises_how_an_item_was_typed(): void
    {
        $this->assertSame(
            PriceBookLine::keyFor('  3/4"   conduit - emt '),
            PriceBookLine::keyFor('3/4" CONDUIT - EMT'),
        );

        // Excel writes an in-cell line break as this literal text.
        $this->assertSame(
            '96" LED STRIP CREE',
            PriceBookLine::keyFor('96" LED STRIP _x000D_ CREE'),
        );
    }

    private function seedRate(): PriceBookItem
    {
        $import = PriceBookImport::create([
            'file_name' => 'Electrical Estimate - DHL PROJECT.xlsx',
            'file_hash' => str_repeat('a', 64),
            'project_name' => 'DHL PROJECT',
            'base_bid_price' => 74195.54,
            'material_tax_pct' => 7.5,
            'line_count' => 2,
            'imported_at' => now(),
        ]);

        foreach ([0.80, 0.90] as $cost) {
            PriceBookLine::create([
                'price_book_import_id' => $import->id,
                'section' => 'BRANCH WIRING',
                'subsection' => 'CONDUITS - LIGHTING',
                'description' => '3/4" CONDUIT - EMT',
                'quantity' => 100,
                'unit' => 'FT',
                'unit_material_cost' => $cost,
                'unit_manhours' => 0.062,
                'match_key' => PriceBookLine::keyFor('3/4" CONDUIT - EMT'),
            ]);
        }

        return PriceBookItem::create([
            'match_key' => PriceBookLine::keyFor('3/4" CONDUIT - EMT'),
            'unit' => 'FT',
            'description' => '3/4" CONDUIT - EMT',
            'section' => 'BRANCH WIRING',
            'subsection' => 'CONDUITS - LIGHTING',
            'unit_material_cost' => 0.85,
            'unit_manhours' => 0.062,
            'sample_count' => 2,
            'min_material_cost' => 0.80,
            'max_material_cost' => 0.90,
            'last_seen_at' => now(),
        ]);
    }
}
