<?php

namespace App\Services\Takeoff;

use Illuminate\Support\Str;

/**
 * Resolves a reviewed symbol name to the rates used for its bill of quantities
 * and estimate lines.
 *
 * Lookup is keyword-based so a renamed symbol ("Pendant Light") still resolves
 * to the right family ("light fixture"); anything unrecognised falls back to the
 * configured default rather than being dropped.
 */
class SymbolCatalog
{
    /**
     * @return array{
     *     category: string,
     *     unit: string,
     *     unit_cost: float,
     *     labor_hours: float,
     *     materials: list<array{description: string, unit: string, unit_cost: float, per_device: float}>,
     *     matched: bool,
     * }
     */
    public function for(string $symbolName): array
    {
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

    /** @param  array<string, mixed>  $entry */
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
        ];
    }
}
