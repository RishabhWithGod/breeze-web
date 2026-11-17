<?php

namespace App\Services\Ai;

use App\Models\AiResult;
use App\Models\SymbolReview;

/**
 * Sends reviewer decisions back to the engine's symbol library.
 *
 * The engine learns from `POST /api/v1/electrical/knowledge/feedback`: a decision
 * is a confirmed / rejected / needs_review status against a learned legend symbol
 * (`symbol_variant_id` / `legend_observation_id`), optionally with a corrected
 * description.
 *
 * Best-effort by design — the decision is already committed locally, so an
 * unreachable engine must never fail the request. Whether the push landed is
 * recorded in the takeoff's audit trail.
 */
class ReviewFeedback
{
    public function __construct(private readonly AiTakeoffClient $client) {}

    /** Confirms the symbol in the engine's library. */
    public function approved(AiResult $result, SymbolReview $review): bool
    {
        return $this->push($result, $review, 'approve', 'confirmed', array_filter([
            'verified_description' => $review->name,
            'review_notes' => $review->isRenamed() ? "Renamed from {$review->ai_name}" : null,
        ]));
    }

    /** Rejects the symbol in the engine's library. */
    public function rejected(AiResult $result, SymbolReview $review): bool
    {
        return $this->push($result, $review, 'reject', 'rejected', [
            'verified_description' => $review->name,
        ]);
    }

    public function renamed(AiResult $result, SymbolReview $review, string $from, string $to): bool
    {
        return $this->push($result, $review, 'rename', 'confirmed', [
            'verified_description' => $to,
            'review_notes' => "Renamed from {$from}",
        ]);
    }

    /** @param  SymbolReview  $target  The row the source folded into. */
    public function merged(AiResult $result, SymbolReview $source, SymbolReview $target): bool
    {
        return $this->push($result, $source, 'merge', 'confirmed', [
            'verified_description' => $target->name,
            'review_notes' => "Merged from {$source->ai_name}",
        ]);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * @param  'approve'|'reject'|'rename'|'merge'  $action
     * @param  'confirmed'|'rejected'|'needs_review'  $status
     * @param  array<string, mixed>  $fields
     */
    private function push(AiResult $result, SymbolReview $review, string $action, string $status, array $fields): bool
    {
        if (! config('ai.learning.enabled')) {
            return false;
        }

        $legend = $this->legendFor($result, $review);

        if ($legend === null) {
            // Only symbols the engine learned from a legend carry an id it can
            // attach feedback to; a plain detection has nothing to update.
            $result->recordHistory(
                'engine_declined',
                "Engine library unchanged ({$action} “{$review->name}”): not a learned legend symbol",
                $review,
                meta: ['action' => $action],
            );

            return false;
        }

        $payload = [
            ...array_filter([
                'symbol_variant_id' => $legend['symbol_variant_id'] ?? null,
                'legend_observation_id' => $legend['observation_id'] ?? null,
            ]),
            'review_status' => $status,
            ...$fields,
        ];

        $outcome = $this->client->pushDecision($payload);

        $result->recordHistory(
            $outcome['delivered'] ? 'engine_learned' : 'engine_declined',
            $outcome['delivered']
                ? "Engine library updated: {$action} “{$review->name}”"
                : "Engine library unchanged ({$action} “{$review->name}”): {$outcome['detail']}",
            $review,
            meta: [
                'action' => $action,
                'payload' => $payload,
                'status' => $outcome['status'],
                'detail' => $outcome['detail'],
            ],
        );

        return $outcome['delivered'];
    }

    /**
     * The engine's legend row for a card, matched on the name it reported — the
     * only link between a detection and a learned symbol in the response.
     *
     * @return array<string, mixed>|null
     */
    private function legendFor(AiResult $result, SymbolReview $review): ?array
    {
        $names = array_map('strtolower', array_filter([$review->ai_name, $review->name]));

        foreach ((array) ($result->original_payload['legend_symbols'] ?? []) as $legend) {
            if (! is_array($legend) || (blank($legend['symbol_variant_id'] ?? null) && blank($legend['observation_id'] ?? null))) {
                continue;
            }

            $candidates = array_map('strtolower', array_filter([$legend['description'] ?? null, $legend['symbol'] ?? null]));

            if (array_intersect($names, $candidates) !== []) {
                return $legend;
            }
        }

        return null;
    }
}
