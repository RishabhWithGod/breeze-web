<?php

namespace App\Services\Estimating;

use App\Models\Estimate;
use App\Models\EstimateBuilderLine;
use App\Models\EstimateItem;
use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Support\Facades\DB;

/**
 * The Estimate Builder's worksheet, and how it becomes an estimate.
 *
 * The worksheet is what the estimator edits: a row per thing being priced, with
 * material and labor side by side. Saving it writes those rows to the estimate as
 * ordinary lines — a material line and a labor line per row, each with the row's
 * markup — and re-adds the estimate's totals, so the estimate, its PDF and its
 * invoices all read the same figures the builder shows.
 */
class EstimateWorksheet
{
    /**
     * Replaces the worksheet with `$lines` and brings the estimate in line with it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array{tax_pct: float|int|string, markup_pct: float|int|string, labor_rate: float|int|string}  $settings
     */
    public function save(Estimate $estimate, array $lines, array $settings, User $user): void
    {
        DB::transaction(function () use ($estimate, $lines, $settings, $user) {
            $estimate->update([
                'tax_pct' => $settings['tax_pct'],
                'markup_pct' => $settings['markup_pct'],
                'builder_labor_rate' => $settings['labor_rate'],
                'commodity_version' => $this->commodityVersion($user),
            ]);

            $kept = [];
            foreach (array_values($lines) as $position => $row) {
                $attributes = [
                    'position' => $position,
                    'description' => trim((string) $row['description']),
                    'commodity' => filled($row['commodity'] ?? null) ? trim((string) $row['commodity']) : null,
                    'unit' => filled($row['unit'] ?? null) ? strtoupper(trim((string) $row['unit'])) : 'EA',
                    'material_qty' => $row['material_qty'] ?? 0,
                    'material_unit_price' => $row['material_unit_price'] ?? 0,
                    'labor_hours' => $row['labor_hours'] ?? 0,
                    'labor_rate' => $row['labor_rate'] ?? 0,
                    'markup_pct' => $row['markup_pct'] ?? 0,
                ];

                $line = isset($row['id'])
                    ? $estimate->builderLines()->find($row['id'])
                    : null;

                if ($line) {
                    $line->update($attributes);
                } else {
                    $line = $estimate->builderLines()->create($attributes + [
                        'source' => $row['source'] ?? EstimateBuilderLine::SOURCE_MANUAL,
                        'source_estimate_item_id' => $row['source_estimate_item_id'] ?? null,
                        'price_book_item_id' => $row['price_book_item_id'] ?? null,
                    ]);
                }

                $kept[] = $line->id;
            }

            // Rows taken off the worksheet go, and their lines on the estimate with them.
            $estimate->builderLines()->whereNotIn('id', $kept)->get()->each->delete();

            $this->writeLines($estimate);
            $estimate->refresh()->recalculateTotals();
        });
    }

    /**
     * Writes each row as a material line and a labor line on the estimate.
     * A row with nothing to price on one side gets no line for it.
     */
    private function writeLines(Estimate $estimate): void
    {
        $lineIds = $estimate->builderLines()->pluck('id');

        EstimateItem::query()->where('estimate_id', $estimate->id)->whereIn('builder_line_id', $lineIds)->delete();

        $position = (int) $estimate->items()->max('position');

        foreach ($estimate->builderLines()->get() as $line) {
            $shared = [
                'estimate_id' => $estimate->id,
                'builder_line_id' => $line->id,
                'markup_pct' => $line->markup_pct,
                'source' => 'builder',
                'price_book_item_id' => $line->price_book_item_id,
                'pricing_source' => $line->price_book_item_id ? 'price-list' : null,
            ];

            if ((float) $line->material_qty > 0 || (float) $line->material_unit_price > 0) {
                EstimateItem::create($shared + [
                    'category' => EstimateItem::CATEGORY_MATERIAL,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => $line->material_qty,
                    'unit_cost' => $line->material_unit_price,
                    'position' => ++$position,
                ]);
            }

            if ((float) $line->labor_hours > 0 || (float) $line->labor_rate > 0) {
                EstimateItem::create($shared + [
                    'category' => EstimateItem::CATEGORY_LABOR,
                    'description' => $line->description.' — labor',
                    'unit' => 'HR',
                    'quantity' => $line->labor_hours,
                    'unit_cost' => $line->labor_rate,
                    'position' => ++$position,
                ]);
            }
        }
    }

    /**
     * Copies a takeoff's lines onto the worksheet, leaving the takeoff untouched.
     * Each keeps the id of the line it came from, so the source is never lost, and a
     * line already copied is not copied twice.
     *
     * @return int how many rows were added
     */
    public function importTakeoff(Estimate $estimate, Estimate $source): int
    {
        $already = $estimate->builderLines()->whereNotNull('source_estimate_item_id')->pluck('source_estimate_item_id')->all();
        $position = (int) $estimate->builderLines()->max('position');
        $added = 0;

        DB::transaction(function () use ($estimate, $source, $already, &$position, &$added) {
            foreach ($source->items()->reorder('position')->with('priceBookItem')->get() as $item) {
                if (in_array($item->id, $already, true)) {
                    continue;
                }

                $isLabor = $item->category === EstimateItem::CATEGORY_LABOR;

                $estimate->builderLines()->create([
                    'position' => ++$position,
                    'description' => $item->description,
                    'commodity' => $item->priceBookItem?->section ?? (EstimateItem::CATEGORY_LABELS[$item->category] ?? null),
                    'unit' => $isLabor ? 'HR' : ($item->unit ?: 'EA'),
                    'material_qty' => $isLabor ? 0 : $item->quantity,
                    'material_unit_price' => $isLabor ? 0 : $item->unit_cost,
                    'labor_hours' => $isLabor ? $item->quantity : 0,
                    'labor_rate' => $isLabor ? $item->unit_cost : 0,
                    'markup_pct' => $estimate->markup_pct,
                    'source' => EstimateBuilderLine::SOURCE_TAKEOFF,
                    'source_estimate_item_id' => $item->id,
                    'price_book_item_id' => $item->price_book_item_id,
                ]);
                $added++;
            }

            $estimate->update(['takeoff_source_estimate_id' => $source->id]);
        });

        return $added;
    }

    /**
     * Which price list an estimate was priced from, as of now: the company's book
     * and when it was last loaded, so a later change to the book is never mistaken
     * for the one this estimate used.
     */
    public function commodityVersion(User $user): string
    {
        $owner = Ownership::bookOwnerId($user->id);
        $items = PriceBookItem::query()->where('user_id', $owner)->count();

        if ($items === 0) {
            $owner = null;
            $items = PriceBookItem::query()->whereNull('user_id')->count();
        }

        $loaded = PriceBookImport::query()->where('user_id', $owner)->latest('id')->value('created_at');

        return $items === 0
            ? 'No price list'
            : 'Price list of '.($loaded ? \Illuminate\Support\Carbon::parse($loaded)->format('M j, Y') : 'unknown date')." · {$items} items";
    }

    /**
     * The price list rows the builder can pick from.
     *
     * @return list<array<string, mixed>>
     */
    public function priceList(User $user, ?string $search = null, int $limit = 60): array
    {
        $owner = Ownership::bookOwnerId($user->id);
        $useOwn = PriceBookItem::query()->where('user_id', $owner)->exists();

        return PriceBookItem::query()
            ->where('user_id', $useOwn ? $owner : null)
            ->search($search)
            ->orderBy('section')
            ->orderBy('description')
            ->limit($limit)
            ->get()
            ->map(fn (PriceBookItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'commodity' => $item->section,
                'materialUnitPrice' => (float) $item->unit_material_cost,
                'hoursPerUnit' => (float) $item->unit_manhours,
            ])
            ->all();
    }
}
