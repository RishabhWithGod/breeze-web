<?php

namespace App\Services\Takeoff;

use App\Events\ReviewFinalised;
use App\Models\AiResult;
use App\Models\FinalSymbol;
use App\Models\SymbolReview;
use App\Models\User;
use App\Services\Ai\ArtefactStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds final_response.json from the reviewed detections.
 *
 * This is a fresh document, not an edited copy of the AI response: only approved
 * rows contribute, they contribute their reviewed count and reviewed name, and
 * rejected rows appear solely as an audit list. Rebuilt each time it is
 * generated, so it can never lag behind the reviews.
 */
class FinalJsonBuilder
{
    public function __construct(
        private readonly ArtefactStore $store,
        private readonly BillOfQuantities $boq,
    ) {}

    /**
     * @return array<string, mixed> The stored final payload.
     */
    public function build(AiResult $result, User $reviewer): array
    {
        $reviews = $result->reviews()->get();
        $approved = $reviews->filter->countsTowardsFinal();

        if ($approved->isEmpty()) {
            throw new RuntimeException(
                'Please approve at least one symbol before finishing this review.'
            );
        }

        return DB::transaction(function () use ($result, $reviews, $approved, $reviewer) {
            $symbols = $this->rebuildFinalSymbols($result, $approved);
            $this->matchEngineBoq($result, $symbols);
            $boq = $this->boq->build($symbols);

            $payload = [
                'project_id' => $result->project_id,
                'upload_id' => $result->upload_id,
                'ai_job_id' => $result->ai_job_id,
                'ai_result_id' => $result->id,
                'generated_at' => now()->toISOString(),
                'generated_by' => ['id' => $reviewer->id, 'name' => $reviewer->name],

                'drawing' => [
                    'name' => $result->project->drawing_name,
                    'project' => $result->project->name,
                    'client' => $result->project->client,
                    'discipline' => $result->project->discipline,
                    'pages' => $result->page_count,
                ],

                'approved_symbols' => $approved->map(fn (SymbolReview $review) => $this->reviewPayload($review))->values()->all(),
                'rejected_symbols' => $reviews
                    ->where('status', SymbolReview::STATUS_REJECTED)
                    ->map(fn (SymbolReview $review) => $this->reviewPayload($review))->values()->all(),
                'modified_symbols' => $reviews
                    ->filter->isModified()
                    ->map(fn (SymbolReview $review) => [
                        ...$this->reviewPayload($review),
                        'ai_name' => $review->ai_name,
                        'ai_count' => $review->ai_count,
                        'renamed' => $review->isRenamed(),
                        'merged_into' => $review->merged_into_id,
                    ])->values()->all(),

                'final_counts' => $symbols->mapWithKeys(fn (FinalSymbol $symbol) => [
                    $symbol->name => $symbol->count,
                ])->all(),

                'final_symbols' => $symbols->map(fn (FinalSymbol $symbol) => [
                    'name' => $symbol->name,
                    'count' => $symbol->count,
                    'confidence' => round($symbol->confidence, 4),
                    'sources' => $symbol->sourceLabels(),
                    'pages' => $symbol->pages,
                ])->values()->all(),

                'boq' => $boq,

                /*
                 * Everything else the engine reported, carried through unchanged so
                 * the reviewed document is self-contained: its own priced bill of
                 * quantities and estimate, plus the schedules and wire data it read
                 * off the drawing.
                 */
                'engine' => [
                    'run_id' => $result->run_id,
                    'project_name' => $result->project_name,
                    'processing_time' => $result->processing_time,
                    'pipeline_status' => $result->pipeline_status ?? [],
                    'warnings' => $result->warnings ?? [],
                    'symbol_counts' => $result->symbol_counts ?? [],
                    'estimate' => $result->ai_estimate ?? [],
                    'boq' => $result->boqLines()->get()->map(fn ($line) => [
                        'item' => $line->item,
                        'description' => $line->description,
                        'quantity' => (float) $line->quantity,
                        'unit' => $line->unit,
                        'unit_price' => (float) $line->unit_price,
                        'subtotal' => (float) $line->subtotal,
                        'matched_symbol' => $line->matched_symbol,
                    ])->all(),
                    'wire_sizes' => $result->wireSizes()->get()->map(fn ($wire) => [
                        'page' => $wire->page,
                        'size' => $wire->size,
                        'context' => $wire->context,
                        'count' => $wire->count,
                    ])->all(),
                    'panel_schedules' => $result->panelSchedules()->get()->map(fn ($panel) => [
                        'page' => $panel->page,
                        'panel_name' => $panel->panel_name,
                        'rows' => $panel->rows,
                        'raw_headers' => $panel->raw_headers,
                    ])->all(),
                    'equipment' => $result->equipment()->get()->map(fn ($item) => [
                        'page' => $item->page,
                        'tag' => $item->tag,
                        'description' => $item->description,
                        'rating' => $item->rating,
                        'quantity' => $item->quantity,
                        'extra' => $item->extra,
                    ])->all(),
                    'circuits' => $result->circuits()->get()->map(fn ($circuit) => [
                        'page' => $circuit->page,
                        'number' => $circuit->number,
                        'description' => $circuit->description,
                        'breaker' => $circuit->breaker,
                        'panel' => $circuit->panel,
                    ])->all(),
                ],

                'metadata' => [
                    'engine_version' => $result->model_version,
                    'run_id' => $result->run_id,
                    'detections_received' => $reviews->count(),
                    'approved' => $approved->count(),
                    'rejected' => $reviews->where('status', SymbolReview::STATUS_REJECTED)->count(),
                    'pending_at_generation' => $reviews->where('status', SymbolReview::STATUS_PENDING)->count(),
                    'modified' => $reviews->filter->isModified()->count(),
                    'ai_item_total' => (int) $reviews->sum('ai_count'),
                    'final_item_total' => (int) $approved->sum('final_count'),
                    'overall_confidence' => $result->overall_confidence,
                    'original_response' => $result->original_path,
                    'needs_review_approved' => $approved
                        ->where('origin', SymbolReview::ORIGIN_NEEDS_REVIEW)
                        ->count(),
                ],
            ];

            $path = $this->store->putFinalResponse($result, $payload);

            $result->update([
                'final_payload' => $payload,
                'final_path' => $path,
                'review_status' => AiResult::REVIEW_FINALISED,
                'finalised_by' => $reviewer->id,
                'finalised_at' => now(),
            ]);

            $result->project->update([
                'review_status' => AiResult::REVIEW_FINALISED,
                'items_count' => (int) $approved->sum('final_count'),
            ]);

            $result->recordHistory(
                'final_json_generated',
                'Final JSON generated from '.$approved->count().' approved symbols',
                to: (string) $approved->sum('final_count').' items',
                meta: ['path' => $path, 'symbols' => $symbols->count()],
            );

            ReviewFinalised::dispatch($result->refresh());

            return $payload;
        });
    }

    /**
     * Links the engine's BOQ lines to the reviewed symbols they price.
     *
     * The engine names lines commercially ("GFCI Receptacle", "Panelboard") while
     * symbols are named as drawn ("GFCI", "panel"), so matching is done on
     * normalised words: exact first, then containment either way. Unmatched lines
     * keep the engine's own quantity.
     *
     * @param  Collection<int, FinalSymbol>  $symbols
     */
    private function matchEngineBoq(AiResult $result, Collection $symbols): void
    {
        $byToken = $symbols->mapWithKeys(fn (FinalSymbol $symbol) => [
            $this->token($symbol->name) => $symbol,
        ]);

        foreach ($result->boqLines()->get() as $line) {
            $token = $this->token($line->item);

            $match = $byToken->get($token) ?? $byToken->first(
                fn (FinalSymbol $symbol, string $key) => $key !== ''
                    && (str_contains($token, $key) || str_contains($key, $token))
            );

            $line->update([
                'final_symbol_id' => $match?->id,
                'matched_symbol' => $match?->name,
            ]);
        }
    }

    /** Lower-cased alphanumerics, for comparing a BOQ item to a symbol name. */
    private function token(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }

    /**
     * Replaces the aggregated rows behind the final symbol table.
     *
     * @param  Collection<int, SymbolReview>  $approved
     * @return Collection<int, FinalSymbol>
     */
    private function rebuildFinalSymbols(AiResult $result, Collection $approved): Collection
    {
        $result->finalSymbols()->delete();

        $groups = $approved
            ->groupBy(fn (SymbolReview $review) => $review->name)
            ->sortByDesc(fn (Collection $group) => $group->sum('final_count'));

        $position = 0;

        foreach ($groups as $name => $group) {
            $count = (int) $group->sum('final_count');

            // A zero final count means nothing to build — it has no place on the
            // signed-off takeoff, the BOQ, or an estimate line.
            if ($count <= 0) {
                continue;
            }

            $result->finalSymbols()->create([
                'project_id' => $result->project_id,
                'name' => $name,
                'count' => $count,
                'confidence' => round((float) $group->avg('confidence'), 4),
                'source_template' => $group->contains->source_template,
                'source_vector' => $group->contains->source_vector,
                'source_vision' => $group->contains->source_vision,
                'source_ocr' => $group->contains->source_ocr,
                // Real pages, not the row's own (possibly null, possibly
                // stale) `page` column — a symbol type can span several
                // pages, and `pageNumbers()` reads that from occurrences.
                'pages' => $group->flatMap(fn (SymbolReview $review) => $review->pageNumbers())
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
                'review_ids' => $group->pluck('id')->all(),
                'was_modified' => $group->filter->isModified()->isNotEmpty(),
                'was_renamed' => $group->filter->isRenamed()->isNotEmpty(),
                'position' => $position++,
            ]);
        }

        return $result->finalSymbols()->get();
    }

    /** @return array<string, mixed> */
    private function reviewPayload(SymbolReview $review): array
    {
        return [
            'id' => $review->external_id,
            'review_id' => $review->id,
            'name' => $review->name,
            'count' => $review->final_count,
            'page' => $review->page,
            'confidence' => round($review->confidence, 4),
            'bbox' => $review->bbox,
            'sources' => $review->sourceLabels(),
            'pipeline' => $review->pipeline,
            'known' => $review->is_known,
            'status' => $review->status,
            'notes' => $review->notes,
            'reviewed_at' => $review->reviewed_at?->toISOString(),
        ];
    }
}
