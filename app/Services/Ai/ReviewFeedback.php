<?php

namespace App\Services\Ai;

use App\Models\AiResult;
use App\Models\SymbolReview;
use Illuminate\Support\Facades\Auth;

/**
 * Sends reviewer decisions back to the engine's symbol library.
 *
 * The engine learns from `POST /api/review/approve|reject|rename|merge`: an
 * approval promotes a candidate symbol, a rejection suppresses it, and renames and
 * merges fold aliases together. `run_id` and `image_id` tie the decision to the
 * crop it came from when both are known.
 *
 * Best-effort by design — the decision is already committed locally, so an
 * unreachable engine must never fail the request. Whether the push landed is
 * recorded in the takeoff's audit trail.
 */
class ReviewFeedback
{
    public function __construct(private readonly AiTakeoffClient $client) {}

    /** Promotes the symbol in the engine's library. */
    public function approved(AiResult $result, SymbolReview $review): bool
    {
        return $this->push($result, $review, 'approve', array_filter([
            'canonical' => $review->name,
            'aliases' => $review->isRenamed() ? [$review->ai_name] : null,
        ], fn ($value) => $value !== null));
    }

    /** Suppresses the symbol in the engine's library. */
    public function rejected(AiResult $result, SymbolReview $review): bool
    {
        return $this->push($result, $review, 'reject', [
            'canonical' => $review->name,
        ]);
    }

    public function renamed(AiResult $result, SymbolReview $review, string $from, string $to): bool
    {
        return $this->push($result, $review, 'rename', [
            'source' => $from,
            'target' => $to,
        ]);
    }

    /** @param  SymbolReview  $target  The row the source folded into. */
    public function merged(AiResult $result, SymbolReview $source, SymbolReview $target): bool
    {
        return $this->push($result, $source, 'merge', [
            'source' => $source->ai_name,
            'target' => $target->name,
        ]);
    }

    /* ------------------------------------------------------------- internals */

    /**
     * @param  'approve'|'reject'|'rename'|'merge'  $action
     * @param  array<string, mixed>  $payload
     */
    private function push(AiResult $result, SymbolReview $review, string $action, array $payload): bool
    {
        if (! config('ai.learning.enabled')) {
            return false;
        }

        $outcome = $this->client->pushDecision($action, [
            ...$payload,
            // The engine attributes learning to an actor; ours is the reviewer.
            'actor' => Auth::user()?->name ?? 'breeze',
            ...array_filter([
                'run_id' => $result->run_id,
                'image_id' => $review->image_id ?: $review->crop_id,
            ], fn ($value) => filled($value)),
        ]);

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
}
