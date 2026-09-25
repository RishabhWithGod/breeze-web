<?php

namespace App\Services\StaticTakeoff;

use App\Models\AiResult;
use App\Models\EstimateItem;
use App\Models\PriceBookLine;
use App\Services\Takeoff\SymbolCatalog;
use Illuminate\Support\Str;

/**
 * Prices a symbol from a static dataset's own bill of quantities first,
 * before the project rate book / price book chain `SymbolCatalog` normally
 * consults.
 *
 * A synced dataset's own material unit cost and per-unit manhours (see
 * `takeoff:static-import`) are used exactly as imported — material and labor
 * come back as two separate figures, the same shape `EstimateBuilder` already
 * expects, so a real labor line is written instead of being folded into (or
 * dropped from) the material price. `labor_rate` overrides the project's own
 * rate book/price book/client-override labor rate the same way, so the labor
 * line prices at the dataset's own composite rate, not a company one.
 *
 * A dataset imported before per-unit labor was tracked (or a boq line with
 * no `unit_material_cost`) falls back to `unit_price` as a single, unsplit
 * figure with no labor line — the same behaviour this class always had.
 *
 * Bound in place of `SymbolCatalog` unconditionally by
 * `StaticTakeoffServiceProvider` — for a project whose latest run did not
 * come from a static dataset, `forProject()` finds nothing to index and every
 * lookup falls straight through to the normal rate book/price book pricing,
 * so this is a no-op for the dynamic flow.
 */
class StaticAwareSymbolCatalog extends SymbolCatalog
{
    /**
     * @var array<string, array{
     *     unit: string,
     *     description: ?string,
     *     unit_price: float,
     *     unit_material_cost: ?float,
     *     unit_manhours: float,
     * }>|null
     */
    private ?array $index = null;

    private ?float $laborRate = null;

    public function forProject(int $projectId, ?int $userId = null): self
    {
        /** @var self $catalog */
        $catalog = parent::forProject($projectId, $userId);
        [$catalog->index, $catalog->laborRate] = $this->indexFor($projectId);

        return $catalog;
    }

    public function for(string $symbolName): array
    {
        $row = $this->index[PriceBookLine::keyFor($symbolName)] ?? null;

        if ($row === null) {
            return parent::for($symbolName);
        }

        $hasSeparateLabor = $row['unit_material_cost'] !== null && $row['unit_manhours'] > 0;

        return [
            'category' => $this->categoryFor($symbolName),
            'unit' => $row['unit'],
            'unit_cost' => $row['unit_material_cost'] ?? $row['unit_price'],
            'labor_hours' => $row['unit_manhours'],
            // Only set once there are hours to price — an unset key here
            // (null) falls through to the project's own labor rate exactly
            // as before, which matters for a dataset with no manhours data.
            'labor_rate' => $hasSeparateLabor ? $this->laborRate : null,
            'materials' => [],
            'matched' => true,
            'description' => $row['description'],
            // Reported as an ordinary vendor-rate-list match, not a distinct
            // source value: `pricing_source` reaches the browser verbatim
            // (`EstimateItemResource`/mobile `EstimateController`), and the
            // frontend's matched/unmatched counts and badges only recognise
            // 'vendor-rate-list' | 'price-book' | 'unmatched'.
            'source' => 'vendor-rate-list',
            'project_rate_item_id' => null,
            'price_book_item_id' => null,
            'confidence' => 'exact',
        ];
    }

    /**
     * @return array{0: array<string, array{unit: string, description: ?string, unit_price: float, unit_material_cost: ?float, unit_manhours: float}>|null, 1: ?float}
     */
    private function indexFor(int $projectId): array
    {
        $result = AiResult::query()
            ->where('project_id', $projectId)
            ->latest('id')
            ->first();

        $boq = $result?->original_payload['boq'] ?? null;

        if (($result?->original_payload['_synced'] ?? false) !== true || ! is_array($boq)) {
            return [null, null];
        }

        $laborRate = is_numeric($result->original_payload['_labor_rate'] ?? null)
            ? (float) $result->original_payload['_labor_rate']
            : null;

        $index = [];

        foreach ($boq as $line) {
            if (! is_array($line) || blank($line['item'] ?? null)) {
                continue;
            }

            $index[PriceBookLine::keyFor((string) $line['item'])] = [
                'unit' => Str::lower((string) ($line['unit'] ?? 'ea')),
                'description' => blank($line['description'] ?? null) ? null : (string) $line['description'],
                'unit_price' => round((float) ($line['unit_price'] ?? 0), 4),
                'unit_material_cost' => isset($line['unit_material_cost'])
                    ? round((float) $line['unit_material_cost'], 4)
                    : null,
                'unit_manhours' => round((float) ($line['unit_manhours'] ?? 0), 6),
            ];
        }

        return [$index, $laborRate];
    }

    private function categoryFor(string $name): string
    {
        // Whole words only — a substring match would wrongly catch, say,
        // "lamp" inside "conduit CLAMP".
        $words = preg_split('/[^a-z0-9]+/', Str::lower($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (array_intersect($words, ['fixture', 'fixtures', 'luminaire', 'light', 'lights', 'lamp', 'pole', 'poles']) !== []) {
            return EstimateItem::CATEGORY_FIXTURE;
        }

        if (array_intersect($words, ['panel', 'switchboard', 'transformer', 'gear', 'disconnect', 'breaker']) !== []) {
            return EstimateItem::CATEGORY_EQUIPMENT;
        }

        return EstimateItem::CATEGORY_MATERIAL;
    }
}
