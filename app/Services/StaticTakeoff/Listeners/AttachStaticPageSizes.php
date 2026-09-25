<?php

namespace App\Services\StaticTakeoff\Listeners;

use App\Events\TakeoffProcessed;

/**
 * Carries a static dataset's optional `page_sizes` extension onto the run's
 * `ai_results.page_sizes` column, so static-mode occurrences get correctly
 * scaled bounding boxes on the drawing overlay without the dynamic engine's
 * lifecycle/debug endpoint (force-disabled in static mode — see
 * `StaticTakeoffServiceProvider`).
 *
 * A no-op for the dynamic flow: the real engine's `AnalysisResult` never
 * carries a `page_sizes` key, so `TakeoffOrchestrator`/`AiResponseNormaliser`
 * need no changes for this to work.
 */
class AttachStaticPageSizes
{
    public function handle(TakeoffProcessed $event): void
    {
        if (! config('static_takeoff.enabled')) {
            return;
        }

        $result = $event->result;
        $raw = $result->original_payload['page_sizes'] ?? null;

        if (! is_array($raw) || $raw === []) {
            return;
        }

        $sizes = collect($raw)
            ->mapWithKeys(fn ($size, $page) => is_array($size)
                ? [(int) $page => [
                    'width' => (float) ($size['width'] ?? $size['w'] ?? 0),
                    'height' => (float) ($size['height'] ?? $size['h'] ?? 0),
                ]]
                : [])
            ->filter(fn (array $size) => $size['width'] > 0 && $size['height'] > 0)
            ->all();

        if ($sizes !== []) {
            $result->updateQuietly(['page_sizes' => $sizes]);
        }
    }
}
