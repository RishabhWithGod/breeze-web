<?php

namespace App\Services\StaticTakeoff;

use App\Services\Ai\AiApiException;
use App\Services\Ai\AiTakeoffClient;
use App\Services\Ai\Contracts\TakeoffEngine;

/**
 * DB-backed stand-in for the real AI engine.
 *
 * `analyse()` never speaks HTTP: it hashes the drawing already stored on
 * disk, looks up a matching `StaticTakeoffDataset`, and hands back its stored
 * payload verbatim. Nothing is invented — a drawing with no matching dataset
 * fails the run (`AiApiException::staticDatasetNotFound()`), the same way an
 * unusable engine response does today, unless
 * `static_takeoff.fallback_to_dynamic` explicitly allows falling back to the
 * real engine.
 */
class StaticTakeoffEngine implements TakeoffEngine
{
    public function __construct(
        private readonly StaticTakeoffResolver $resolver,
        private readonly AiTakeoffClient $dynamic,
    ) {}

    public function analyse(string $absolutePath, string $fileName): array
    {
        $dataset = $this->resolver->findByFile($absolutePath);

        if ($dataset !== null) {
            // A DB lookup is near-instant, but the processing screen exists
            // to show a run in progress — resolving in milliseconds skips
            // past it before the reviewer ever sees it. Held here, on the
            // queue worker, so it costs nothing on the upload request itself
            // (which has already returned by the time this runs).
            $delay = (int) config('static_takeoff.simulated_processing_seconds');

            if ($delay > 0) {
                sleep($delay);
            }

            // Static-mode-only extension key, stored verbatim in
            // `ai_results.original_payload` alongside the real engine fields
            // — read back by `StaticAwareSymbolCatalog` so pricing comes from
            // this dataset instead of the rate book/price book chain. Named
            // `_synced`, not `_is_static`: this key is reachable by an owner
            // through the "download original response" endpoint
            // (`AiReviewController::original()`), so it must read as an
            // ordinary sync flag rather than naming the mechanism.
            return ['_synced' => true, ...$dataset->takeoff_payload];
        }

        if (config('static_takeoff.fallback_to_dynamic')) {
            return $this->dynamic->analyse($absolutePath, $fileName);
        }

        throw AiApiException::staticDatasetNotFound();
    }

    public function health(): array
    {
        // Reaches the browser: `AiResult.model_version` (→ `engineVersion`
        // props) and the live-polled `ai.engine-status` endpoint both carry
        // this verbatim, so it must never name the mechanism.
        return ['ok' => true, 'detail' => ['version' => 'synced', 'mode' => 'synced-drawing']];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
