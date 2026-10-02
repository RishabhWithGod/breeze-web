<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\PriceBookItem;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The company's commodity list (the materials it buys and installs), for the
 * crew to pick from when adding a material on site. Names and units only —
 * pricing stays with the office.
 */
class CommodityController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->company_id !== null, 403, 'No company is set up for this account.');

        $owner = (int) Ownership::bookOwnerId($user->id);
        $search = trim((string) $request->query('search'));

        $items = PriceBookItem::query()
            ->where('user_id', $owner)
            ->active()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhere('item_code', 'like', "%{$search}%")
                ->orWhere('section', 'like', "%{$search}%")))
            ->orderBy('section')
            ->orderBy('description')
            ->limit(40)
            ->get(['id', 'section', 'item_code', 'description', 'unit']);

        return $this->ok([
            'items' => $items->map(fn (PriceBookItem $i) => [
                'id' => $i->id,
                'category' => $i->section ?? 'General',
                'itemCode' => $i->item_code,
                'description' => $i->description,
                'unit' => $i->unit,
            ])->all(),
        ]);
    }
}
