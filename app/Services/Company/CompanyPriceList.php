<?php

namespace App\Services\Company;

use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use Illuminate\Support\Facades\DB;

/**
 * Writes items into a company's price book — the commodity list.
 *
 * Every item set here is the company's own say, so it is pinned: a later import of an
 * estimating workbook refreshes what it saw but never overwrites it.
 */
class CompanyPriceList
{
    /**
     * Adds each item, or updates the one already on the list (same code, or same name and unit).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{created: int, updated: int}
     */
    public function upsert(int $ownerId, array $items): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($ownerId, $items, &$created, &$updated) {
            foreach ($items as $data) {
                $key = PriceBookLine::keyFor($data['description']);

                $item = PriceBookItem::query()->where('user_id', $ownerId)->where('match_key', $key)->where('unit', $data['unit'])->first()
                    ?? (filled($data['item_code'] ?? null)
                        ? PriceBookItem::query()->where('user_id', $ownerId)->where('item_code', $data['item_code'])->first()
                        : null);

                $exists = $item !== null;
                $item ??= new PriceBookItem(['user_id' => $ownerId, 'sample_count' => 1]);

                $item->fill($this->attributes($data, $key, $item));
                $item->save();

                $exists ? $updated++ : $created++;
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * The columns an item's data becomes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributes(array $data, string $key, PriceBookItem $item): array
    {
        return [
            'match_key' => $key,
            'description' => $data['description'],
            'item_code' => filled($data['item_code'] ?? null) ? $data['item_code'] : $item->item_code,
            'section' => $data['category'],
            'unit' => $data['unit'],
            'unit_material_cost' => $data['material_price'],
            'unit_manhours' => $data['labor_hours'],
            'markup_pct' => array_key_exists('markup_pct', $data) && $data['markup_pct'] !== null ? $data['markup_pct'] : $item->markup_pct,
            'is_pinned' => true,
            'archived_at' => null,
            'last_seen_at' => now(),
        ];
    }
}
