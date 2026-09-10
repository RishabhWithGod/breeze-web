<?php

namespace App\Services\Takeoff;

use App\Models\FinalSymbol;
use App\Models\SymbolReview;
use Illuminate\Support\Collection;

/**
 * Expands reviewed symbol counts into a bill of quantities: one line per device
 * family plus the consumables each device pulls with it.
 *
 * Quantities come only from the reviewed counts, never from the AI response.
 */
class BillOfQuantities
{
    public function __construct(private readonly SymbolCatalog $catalog) {}

    /**
     * @param  Collection<int, FinalSymbol>  $symbols
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     materials: list<array<string, mixed>>,
     *     totals: array{devices: int, labor_hours: float, material_cost: float},
     * }
     */
    public function build(Collection $symbols): array
    {
        return $this->compile($symbols->map(fn (FinalSymbol $symbol) => [
            'name' => $symbol->name,
            'count' => (int) $symbol->count,
            'confidence' => (float) $symbol->confidence,
            'pages' => $symbol->pages ?? [],
            'final_symbol_id' => $symbol->id,
        ]));
    }

    /**
     * The same expansion from the engine's own counts, before anything is reviewed.
     *
     * Used for the provisional job and estimate raised the moment a drawing comes
     * back from the engine: same rates, same shape, so signing off the review later
     * only changes the numbers — never the structure.
     *
     * @param  Collection<int, SymbolReview>  $reviews
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     materials: list<array<string, mixed>>,
     *     totals: array{devices: int, labor_hours: float, material_cost: float},
     * }
     */
    public function fromReviews(Collection $reviews): array
    {
        return $this->compile($reviews->map(fn (SymbolReview $review) => [
            'name' => $review->name,
            'count' => (int) $review->final_count,
            'confidence' => (float) $review->confidence,
            'pages' => $review->page !== null ? [$review->page] : [],
            'final_symbol_id' => null,
        ]));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $devices
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     materials: list<array<string, mixed>>,
     *     totals: array{devices: int, labor_hours: float, material_cost: float},
     * }
     */
    private function compile(Collection $devices): array
    {
        $lines = [];
        $materials = [];

        foreach ($devices as $device) {
            $rates = $this->catalog->for($device['name']);
            $count = (int) $device['count'];

            $lines[] = [
                'symbol' => $device['name'],
                /*
                 * What the company calls it. A drawing labels a fixture "EM2";
                 * the estimating workbook says "EM2, NEW BATTERY 2/HEAD EM
                 * FIXTURE" — the same item, described well enough to order.
                 * Null when the price book has never seen it, so the screens
                 * keep showing the symbol's own name.
                 */
                'description' => $rates['description'],
                'count' => $count,
                'unit' => $rates['unit'],
                'category' => $rates['category'],
                'unit_cost' => $rates['unit_cost'],
                'extended_cost' => round($rates['unit_cost'] * $count, 2),
                'labor_hours' => round($rates['labor_hours'] * $count, 2),
                'confidence' => round($device['confidence'], 4),
                'pages' => $device['pages'],
                'rate_matched' => $rates['matched'],
                // Where the rate came from: a bid the company sent out, or a
                // plausible constant standing in for one.
                'rate_source' => $rates['source'],
                'final_symbol_id' => $device['final_symbol_id'],
            ];

            foreach ($rates['materials'] as $material) {
                $key = $material['description'].'|'.$material['unit'];
                $quantity = round($material['per_device'] * $count, 2);

                $materials[$key] ??= [
                    'description' => $material['description'],
                    'unit' => $material['unit'],
                    'unit_cost' => $material['unit_cost'],
                    'quantity' => 0.0,
                    'extended_cost' => 0.0,
                ];

                $materials[$key]['quantity'] = round($materials[$key]['quantity'] + $quantity, 2);
                $materials[$key]['extended_cost'] = round(
                    $materials[$key]['quantity'] * $material['unit_cost'],
                    2,
                );
            }
        }

        $materials = array_values($materials);

        return [
            'lines' => $lines,
            'materials' => $materials,
            'totals' => [
                'devices' => (int) collect($lines)->sum('count'),
                'labor_hours' => round((float) collect($lines)->sum('labor_hours'), 2),
                'material_cost' => round(
                    (float) collect($lines)->sum('extended_cost')
                    + (float) collect($materials)->sum('extended_cost'),
                    2,
                ),
            ],
        ];
    }
}
