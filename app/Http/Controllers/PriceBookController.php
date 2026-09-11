<?php

namespace App\Http\Controllers;

use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Inertia\Response;

/**
 * This user's own rates — or, while they have not uploaded a rate list of
 * their own, the universal book everyone falls back to.
 *
 * A screen rather than a database client, because the people who need to check
 * a rate are estimators, not anyone with MySQL open. Read-only for now: the
 * numbers arrive through `pricebook:import` or the project form's upload, and
 * nothing here writes them.
 */
class PriceBookController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:24'],
            'section' => ['nullable', 'string', 'max:120'],
        ]);

        $search = trim($filters['search'] ?? '');
        $unit = $filters['unit'] ?? 'all';
        $section = $filters['section'] ?? 'all';
        $scope = $this->scopeFor($request);

        $items = PriceBookItem::query()
            ->where('user_id', $scope)
            ->search($search)
            ->when($unit !== 'all', fn ($query) => $query->where('unit', $unit))
            ->when($section !== 'all', fn ($query) => $query->where('section', $section))
            /*
             * Most-priced first. An item seen on sixty jobs is a rate to trust;
             * one seen once is a rate to check, and burying it under an
             * alphabetical list is how it never gets checked.
             */
            ->orderByDesc('sample_count')
            ->orderBy('description')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PriceBookItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'section' => $item->section,
                'subsection' => $item->subsection,
                'unitMaterialCost' => $item->unit_material_cost === null ? null : (float) $item->unit_material_cost,
                'unitManhours' => $item->unit_manhours === null ? null : (float) $item->unit_manhours,
                'sampleCount' => $item->sample_count,
                'minMaterialCost' => $item->min_material_cost === null ? null : (float) $item->min_material_cost,
                'maxMaterialCost' => $item->max_material_cost === null ? null : (float) $item->max_material_cost,
                'isPinned' => $item->is_pinned,
            ]);

        return Inertia::render('PriceBook', [
            'items' => JsonResource::collection($items),
            'filters' => ['search' => $search, 'unit' => $unit, 'section' => $section],
            // Whether this list is the user's own upload or the shared fallback,
            // so the screen can say so.
            'isUniversal' => $scope === null,
            'units' => PriceBookItem::query()->where('user_id', $scope)->distinct()->orderBy('unit')->pluck('unit'),
            'sections' => PriceBookItem::query()->where('user_id', $scope)->whereNotNull('section')
                ->distinct()->orderBy('section')->pluck('section'),
            'totals' => [
                'items' => PriceBookItem::query()->where('user_id', $scope)->count(),
                'lines' => PriceBookLine::query()->where('user_id', $scope)->count(),
                'imports' => PriceBookImport::query()->where('user_id', $scope)->count(),
            ],
            // Where the rates came from, so a figure on this screen can always
            // be traced to a bid somebody actually sent out.
            'imports' => PriceBookImport::query()->where('user_id', $scope)->orderByDesc('id')->get()->map(fn (PriceBookImport $import) => [
                'id' => $import->id,
                'projectName' => $import->project_name ?? $import->file_name,
                'fileName' => $import->file_name,
                'lineCount' => $import->line_count,
                'baseBidPrice' => $import->base_bid_price === null ? null : (float) $import->base_bid_price,
                'materialTaxPct' => $import->material_tax_pct === null ? null : (float) $import->material_tax_pct,
                'overheadPct' => $import->overhead_pct === null ? null : (float) $import->overhead_pct,
                'profitPct' => $import->profit_pct === null ? null : (float) $import->profit_pct,
                'electricianRate' => $import->electrician_rate === null ? null : (float) $import->electrician_rate,
                'compositeLaborRate' => $import->composite_labor_rate === null ? null : (float) $import->composite_labor_rate,
                'totalManhours' => $import->total_manhours === null ? null : (float) $import->total_manhours,
                'importedAt' => $import->imported_at?->toISOString(),
            ]),
        ]);
    }

    /** Every line this item's rate was worked out from. */
    public function show(Request $request, PriceBookItem $priceBookItem): Response
    {
        // Not this user's own book and not the universal fallback either —
        // somebody else's rate, which this screen never shows.
        abort_unless($priceBookItem->user_id === $this->scopeFor($request), 404);

        $lines = PriceBookLine::where('user_id', $priceBookItem->user_id)
            ->where('match_key', $priceBookItem->match_key)
            ->where('unit', $priceBookItem->unit)
            ->with('import:id,project_name,file_name')
            ->orderByDesc('price_book_import_id')
            ->get()
            ->map(fn (PriceBookLine $line) => [
                'id' => $line->id,
                'project' => $line->import?->project_name ?? $line->import?->file_name,
                'section' => $line->section,
                'subsection' => $line->subsection,
                'description' => $line->description,
                'quantity' => $line->quantity === null ? null : (float) $line->quantity,
                'unit' => $line->unit,
                'unitMaterialCost' => $line->unit_material_cost === null ? null : (float) $line->unit_material_cost,
                'unitManhours' => $line->unit_manhours === null ? null : (float) $line->unit_manhours,
                'totalCost' => $line->total_cost === null ? null : (float) $line->total_cost,
                'sourceRow' => $line->source_row,
            ]);

        return Inertia::render('PriceBookItem', [
            'item' => [
                'id' => $priceBookItem->id,
                'description' => $priceBookItem->description,
                'unit' => $priceBookItem->unit,
                'section' => $priceBookItem->section,
                'subsection' => $priceBookItem->subsection,
                'unitMaterialCost' => $priceBookItem->unit_material_cost === null ? null : (float) $priceBookItem->unit_material_cost,
                'unitManhours' => $priceBookItem->unit_manhours === null ? null : (float) $priceBookItem->unit_manhours,
                'sampleCount' => $priceBookItem->sample_count,
                'isPinned' => $priceBookItem->is_pinned,
            ],
            'lines' => $lines,
        ]);
    }

    /**
     * Whose book this request reads: the signed-in user's own, or — while
     * they have never uploaded a rate list — the universal one.
     *
     * The same rule {@see \App\Services\Takeoff\PriceBookLookup} prices
     * estimates with, so this screen always shows the book an estimate would
     * actually be raised against.
     */
    private function scopeFor(Request $request): ?int
    {
        $userId = $request->user()->id;

        return PriceBookItem::query()->where('user_id', $userId)->exists() ? $userId : null;
    }
}
