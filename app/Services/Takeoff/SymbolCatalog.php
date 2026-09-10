<?php

namespace App\Services\Takeoff;

use App\Models\EstimateItem;
use Illuminate\Support\Str;

/**
 * Resolves a reviewed symbol name to the rates its bill of quantities and
 * estimate lines are priced at.
 *
 * The company's own price book is asked first — rates read off estimates it has
 * actually sent out, with the install hours an estimator wrote beside each one.
 * Only when the price book has never seen the item does this fall back to the
 * keyword catalog in config, which is a set of plausible numbers and nothing
 * more. Every answer says which of the two it came from, so a line priced on a
 * guess can be marked as one instead of passing for a quote.
 */
class SymbolCatalog
{
    public function __construct(private readonly PriceBookLookup $priceBook) {}

    /**
     * @return array{
     *     category: string,
     *     unit: string,
     *     unit_cost: float,
     *     labor_hours: float,
     *     materials: list<array{description: string, unit: string, unit_cost: float, per_device: float}>,
     *     matched: bool,
     *     description: string|null,
     *     source: string,
     *     price_book_item_id: int|null,
     *     confidence: string|null,
     * }
     */
    public function for(string $symbolName): array
    {
        $priced = $this->priceBook->find($symbolName);

        if ($priced !== null) {
            return $this->fromPriceBook($priced);
        }

        $needle = Str::lower($symbolName);

        foreach ((array) config('estimating.catalog') as $entry) {
            foreach ((array) ($entry['match'] ?? []) as $keyword) {
                if (str_contains($needle, Str::lower($keyword))) {
                    return $this->shape($entry, matched: true);
                }
            }
        }

        return $this->shape((array) config('estimating.default'), matched: false);
    }

    /**
     * A rate the company has charged before.
     *
     * The description comes back too, and it is the more useful half: a drawing
     * labels a fixture `EM2`, the workbook calls it "EM2, NEW BATTERY 2/HEAD EM
     * FIXTURE", and the second is what belongs on an estimate somebody has to
     * read. No consumables are attached — the workbooks price boxes, rings,
     * screws and whips as lines of their own, so adding the config catalog's
     * per-device extras on top would charge for them twice.
     *
     * @param  array<string, mixed>  $priced
     * @return array<string, mixed>
     */
    private function fromPriceBook(array $priced): array
    {
        $item = $priced['item'];

        return [
            'category' => $this->categoryFor($item->section, $item->description),
            'unit' => Str::lower($priced['unit'] ?: 'ea'),
            /*
             * Zero is a real answer here, not a missing one: these workbooks
             * carry fixtures the owner supplies and sensors quoted elsewhere,
             * priced at labour only. The line still belongs on the estimate.
             */
            'unit_cost' => round((float) ($priced['unit_cost'] ?? 0), 4),
            'labor_hours' => round((float) ($priced['labor_hours'] ?? 0), 6),
            'materials' => [],
            'matched' => true,
            'description' => $priced['description'],
            'source' => 'price-book',
            'price_book_item_id' => $item->id,
            'confidence' => $priced['confidence'],
        ];
    }

    /**
     * Which section of the estimate an item belongs in.
     *
     * The workbooks already group their work, so that grouping is used where it
     * maps cleanly onto the estimate's own sections; where it does not, the
     * item's name decides.
     */
    private function categoryFor(?string $section, string $description): string
    {
        $sections = [
            'LIGHTING FIXTURES' => EstimateItem::CATEGORY_FIXTURE,
            'DISTRIBUTION' => EstimateItem::CATEGORY_EQUIPMENT,
            'EQUIPMENT' => EstimateItem::CATEGORY_EQUIPMENT,
        ];

        if ($section !== null && isset($sections[mb_strtoupper($section)])) {
            return $sections[mb_strtoupper($section)];
        }

        $needle = Str::lower($description);

        foreach (['fixture', 'luminaire', 'light', 'lamp', 'troffer'] as $word) {
            if (str_contains($needle, $word)) {
                return EstimateItem::CATEGORY_FIXTURE;
            }
        }

        foreach (['panel', 'switchboard', 'transformer', 'gear', 'disconnect', 'breaker'] as $word) {
            if (str_contains($needle, $word)) {
                return EstimateItem::CATEGORY_EQUIPMENT;
            }
        }

        return EstimateItem::CATEGORY_MATERIAL;
    }

    /**
     * A rate from the config catalog: plausible, and nobody's actual price.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function shape(array $entry, bool $matched): array
    {
        return [
            'category' => (string) $entry['category'],
            'unit' => (string) ($entry['unit'] ?? 'ea'),
            'unit_cost' => round((float) ($entry['unit_cost'] ?? 0), 2),
            'labor_hours' => round((float) ($entry['labor_hours'] ?? 0), 3),
            'materials' => collect($entry['materials'] ?? [])
                ->map(fn (array $material) => [
                    'description' => (string) $material[0],
                    'unit' => (string) ($material[1] ?? 'ea'),
                    'unit_cost' => round((float) ($material[2] ?? 0), 2),
                    // Quantity of this consumable per device (default one each).
                    'per_device' => round((float) ($material[3] ?? 1), 2),
                ])
                ->all(),
            'matched' => $matched,
            // Nothing to rename by: the config catalog is keyed on keywords, so
            // it has no fuller wording of its own to offer.
            'description' => null,
            'source' => 'catalog',
            'price_book_item_id' => null,
            'confidence' => null,
        ];
    }
}
