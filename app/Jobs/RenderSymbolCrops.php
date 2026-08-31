<?php

namespace App\Jobs;

use App\Models\AiResult;
use App\Services\Ai\CropRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuts each of a run's detections out of its rendered page previews, locally,
 * as the fallback path when the engine has not supplied crop images (lifecycle
 * disabled, or the engine simply did not return one for a given card).
 *
 * Depends on RenderDrawingPreviews having already produced page-NN.png for
 * this upload. If the previews are not ready yet this job releases itself
 * back onto the queue a few times rather than rendering nothing.
 */
class RenderSymbolCrops implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 5;

    public int $backoff = 10;

    public function __construct(public readonly int $aiResultId) {}

    public function handle(CropRenderer $renderer): void
    {
        $result = AiResult::find($this->aiResultId);

        if (! $result) {
            return;
        }

        $upload = $result->upload;

        if (! $upload) {
            return;
        }

        if (($upload->page_count ?? 0) < 1) {
            // Previews not rendered yet (or the render failed) — wait for it.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            }

            return;
        }

        $reviews = $result->reviews()->whereNull('crop_path')->get();

        if ($reviews->isEmpty()) {
            return;
        }

        try {
            $pageSizes = collect($result->page_sizes ?? [])->all();
            $written = $renderer->renderFor($upload, $reviews, $pageSizes);

            Log::info('Symbol crops rendered locally', [
                'ai_result_id' => $result->id,
                'written' => $written,
                'candidates' => $reviews->count(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Local symbol crop render failed', [
                'ai_result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
