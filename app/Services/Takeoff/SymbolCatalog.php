<?php

namespace App\Services\Takeoff;

use App\Models\EstimateItem;
use App\Services\Estimating\ProjectRateBook;
use Illuminate\Support\Str;

/**
 * Resolves a reviewed symbol name to the rates its bill of quantities and
 * estimate lines are priced at.
 *
 * Two sources, tried in order, because the project's own workbook is worth
 * more than a company-wide guess but a guess still beats nothing:
 *
 *   1. this project's own uploaded vendor rate list — a rate the drawing's
 *      own vendor actually quoted;
 *   2. failing that, the estimator's price book — their own uploaded book
 *      where they have one, the shared universal book where they do not;
 *   3. failing both, unmatched — priced at zero rather than a plausible
 *      number nobody actually charged, so the estimator always knows which
 *      lines are real quotes and which still need a rate typed in by hand.
 */
class SymbolCatalog
{
    public function __construct(
        private readonly ProjectRateBook $rateBook,
        private readonly PriceBookLookup $priceBook,
    ) {}

    /**
     * A copy of this catalog reading this project's own rate list first, then
     * `$userId`'s price book (their own, or the universal one) as a fallback.
     */
    public function forProject(int $projectId, ?int $userId = null): self
    {
        return new self(
            $this->rateBook->forProject($projectId),
            $this->priceBook->forUser($userId),
        );
    }

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
     *     project_rate_item_id: int|null,
     *     price_book_item_id: int|null,
     *     confidence: string|null,
     * }
     */
    public function for(string $symbolName): array
    {
        $priced = $this->rateBook->find($symbolName);

        if ($priced !== null) {
            return $this->fromRateBook($priced);
        }

        $priced = $this->priceBook->find($symbolName);

        if ($priced !== null) {
            return $this->fromPriceBook($priced);
        }

        return $this->unmatched($symbolName);
    }

    /**
     * A rate this project's own vendor rate list has charged.
     *
     * The description comes back too, and it is the more useful half: a drawing
     * labels a fixture `EM2`, the workbook calls it "EM2, NEW BATTERY 2/HEAD EM
     * FIXTURE", and the second is what belongs on an estimate somebody has to
     * read.
     *
     * @param  array<string, mixed>  $priced
     * @return array<string, mixed>
     */
    private function fromRateBook(array $priced): array
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
            'source' => 'vendor-rate-list',
            'project_rate_item_id' => $item->id,
            'price_book_item_id' => null,
            'confidence' => $priced['confidence'],
        ];
    }

    /**
     * A rate the price book has charged, reached only once this project's own
     * rate list has had nothing to say about the symbol.
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
            'unit_cost' => round((float) ($priced['unit_cost'] ?? 0), 4),
            'labor_hours' => round((float) ($priced['labor_hours'] ?? 0), 6),
            'materials' => [],
            'matched' => true,
            'description' => $priced['description'],
            'source' => 'price-book',
            'project_rate_item_id' => null,
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
     * Nothing on file for this symbol, in either the project's own rate list
     * or the price book. Priced at zero rather than a guess — the line still
     * belongs on the estimate (so nothing on the drawing goes unaccounted
     * for), but its rate is the estimator's to type in, not a plausible
     * number standing in for one.
     */
    private function unmatched(string $symbolName): array
    {
        return [
            'category' => $this->categoryFor(null, $symbolName),
            'unit' => 'ea',
            'unit_cost' => 0.0,
            'labor_hours' => 0.0,
            'materials' => [],
            'matched' => false,
            // Nothing to rename by: neither book has ever seen this one, so
            // there is no fuller wording to offer in place of the symbol.
            'description' => null,
            'source' => 'unmatched',
            'project_rate_item_id' => null,
            'price_book_item_id' => null,
            'confidence' => null,
        ];
    }
}
