<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Estimates, as the mobile app sees them: the same `ownedBy()` scope
 * `EstimateController::index` (web) uses — only estimates the signed-in
 * manager themselves created, addenda excluded exactly as the web list
 * excludes them (an addendum is never its own row; it belongs to, and is
 * only ever shown from, the original estimate it adds scope to).
 *
 * Deliberately minimal, matching the mobile Jobs endpoint's own precedent
 * (`JobController::index`): no server-side search/status/date filters —
 * the mobile list is small enough that the app filters client-side — and
 * no material/labor/equipment/markup cost breakdown, only the one total a
 * list row needs. Those stay exclusively in the manager-facing web detail
 * screen, same reasoning `JobController` gives for never sending `budget`.
 */
class EstimateController extends Controller
{
    use ApiResponses;

    public function index(Request $request): JsonResponse
    {
        $estimates = Estimate::query()
            ->ownedBy($request->user())
            ->where('kind', '!=', Estimate::KIND_ADDENDUM)
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'estimates' => $estimates->getCollection()->map(fn (Estimate $estimate) => [
                'id' => $estimate->id,
                'number' => $estimate->number,
                'client' => $estimate->client,
                'project' => $estimate->project,
                'issuedOn' => $estimate->issued_on->toDateString(),
                'amount' => (float) $estimate->amount,
                'status' => $estimate->status,
            ])->all(),
            'meta' => [
                'currentPage' => $estimates->currentPage(),
                'lastPage' => $estimates->lastPage(),
                'perPage' => $estimates->perPage(),
                'total' => $estimates->total(),
            ],
        ]);
    }

    /**
     * The detail screen's shape — everything `index` deliberately leaves
     * out (line items, the cost breakdown, the linked takeoff) — since this
     * is always the estimate's own owner viewing it, not the field crew
     * `index`'s minimal row is designed for.
     */
    public function show(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless($estimate->user_id === $request->user()->id, 403);

        $estimate->load([
            'items' => fn ($query) => $query->orderBy('category')->orderBy('position'),
            'takeoffProject', 'aiResult.wireSizes', 'aiResult.equipment', 'aiResult.panelSchedules',
        ]);

        $matchedCount = $estimate->items
            ->whereIn('pricing_source', ['vendor-rate-list', 'price-book'])
            ->count();
        $engine = $estimate->aiResult?->ai_estimate ?? [];

        return $this->ok([
            'id' => $estimate->id,
            'number' => $estimate->number,
            'client' => $estimate->client,
            'project' => $estimate->project,
            'projectId' => $estimate->project_id,
            'aiResultId' => $estimate->ai_result_id,
            'fromTakeoff' => $estimate->ai_result_id !== null,
            'issuedOn' => $estimate->issued_on->toDateString(),
            'createdAt' => $estimate->created_at?->toISOString(),
            'amount' => (float) $estimate->amount,
            'status' => $estimate->status,
            'notes' => $estimate->notes,
            'materialTotal' => (float) $estimate->material_total,
            'laborTotal' => (float) $estimate->labor_total,
            'equipmentTotal' => (float) $estimate->equipment_total,
            'subtotal' => (float) $estimate->subtotal,
            'markupPct' => (float) $estimate->markup_pct,
            'markupTotal' => (float) $estimate->markup_total,
            'taxPct' => (float) $estimate->tax_pct,
            'taxTotal' => (float) $estimate->tax_total,
            'grandTotal' => (float) $estimate->grand_total,
            'laborHours' => round(
                (float) $estimate->items->where('category', EstimateItem::CATEGORY_LABOR)->sum('quantity'),
                2,
            ),
            // What the AI engine priced before review, for comparison — same
            // fields `EstimateBuilder::totalsFor()` sends web.
            'engineSubtotal' => (float) ($engine['subtotal'] ?? 0),
            'engineGrandTotal' => (float) ($engine['grand_total'] ?? 0),
            'engineLineCount' => (int) ($engine['line_count'] ?? 0),
            // Matched = priced off a known rate (this project's own vendor
            // rate list, or the price book); unmatched = priced at zero,
            // waiting on a rate.
            'matchedLines' => $matchedCount,
            'unmatchedLines' => $estimate->items->count() - $matchedCount,
            'drawingName' => $estimate->takeoffProject?->drawing_name,
            // False while the lines still carry the AI engine's own
            // pre-review quantities.
            'reviewed' => $estimate->aiResult === null ? true : $estimate->aiResult->isFinalised(),
            'lineItems' => $estimate->items->map(fn (EstimateItem $item) => [
                'id' => $item->id,
                'category' => $item->category,
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'unitCost' => (float) $item->unit_cost,
                'total' => (float) $item->total,
                'source' => $item->source,
                'pricingSource' => $item->pricing_source,
                'pricingConfidence' => $item->pricing_confidence,
            ])->all(),
            'drawingData' => [
                'wireSizes' => $estimate->aiResult?->wireSizes->map(fn ($wire) => [
                    'page' => $wire->page,
                    'size' => $wire->size,
                    'context' => $wire->context,
                    'count' => $wire->count,
                ])->all() ?? [],
                'equipment' => $estimate->aiResult?->equipment->map(fn ($item) => [
                    'page' => $item->page,
                    'tag' => $item->tag,
                    'description' => $item->description,
                    'rating' => $item->rating,
                    'quantity' => $item->quantity,
                ])->all() ?? [],
                'panelSchedules' => $estimate->aiResult?->panelSchedules->map(fn ($panel) => [
                    'page' => $panel->page,
                    'panelName' => $panel->panel_name,
                    'rows' => $panel->rows ?? [],
                    'rawHeaders' => $panel->raw_headers ?? [],
                ])->all() ?? [],
            ],
        ]);
    }

    /**
     * Header fields — mobile's counterpart to web's own
     * `EstimateDetailController::update()`. The project/client an estimate
     * is on isn't editable here (that's a reassignment, not an edit — no
     * mobile picker for it this pass); everything else uses the exact same
     * validation and recalculation web does.
     */
    public function update(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless($estimate->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(Estimate::STATUSES)],
            'issued_on' => ['required', 'date'],
            'markup_pct' => ['required', 'numeric', 'min:0', 'max:200'],
            'tax_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $estimate->update($validated);
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_updated',
            "Estimate {$estimate->number} updated",
            to: '$'.number_format((float) $estimate->fresh()->grand_total, 2),
        );

        return $this->ok(['id' => $estimate->id], "Estimate {$estimate->number} updated.");
    }

    /** `POST /estimates/{estimate}/items` — a manual line, same fields web's form takes. */
    public function storeItem(Request $request, Estimate $estimate): JsonResponse
    {
        abort_unless($estimate->user_id === $request->user()->id, 403);

        $validated = $this->validatedItem($request);

        $item = $estimate->items()->create([
            ...$validated,
            'source' => 'manual',
            'position' => (int) $estimate->items()->max('position') + 1,
        ]);

        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_added',
            "Added \"{$item->description}\" to estimate {$estimate->number}",
            to: '$'.number_format((float) $item->total, 2),
        );

        return $this->created(['id' => $item->id], "\"{$item->description}\" added.");
    }

    public function updateItem(Request $request, Estimate $estimate, EstimateItem $item): JsonResponse
    {
        abort_unless($estimate->user_id === $request->user()->id, 403);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $validated = $this->validatedItem($request);

        $before = (float) $item->total;
        $item->update($validated);
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_updated',
            "Updated \"{$item->description}\" on estimate {$estimate->number}",
            from: '$'.number_format($before, 2),
            to: '$'.number_format((float) $item->fresh()->total, 2),
        );

        return $this->ok(['id' => $item->id], "\"{$item->description}\" updated.");
    }

    public function destroyItem(Request $request, Estimate $estimate, EstimateItem $item): JsonResponse
    {
        abort_unless($estimate->user_id === $request->user()->id, 403);
        abort_unless($item->estimate_id === $estimate->id, 404);

        $description = $item->description;
        $item->delete();
        $estimate->recalculateTotals();
        app(EstimateBuilder::class)->syncJobBudget($estimate);

        $estimate->aiResult?->recordHistory(
            'estimate_line_removed',
            "Removed \"{$description}\" from estimate {$estimate->number}",
        );

        return $this->ok(null, "\"{$description}\" removed.");
    }

    /** @return array<string, mixed> */
    private function validatedItem(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(EstimateItem::CATEGORIES)],
            'description' => ['required', 'string', 'max:200'],
            'unit' => ['required', 'string', 'max:12'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }
}
