<?php

namespace App\Jobs;

use App\Models\Upload;
use App\Services\Ai\ArtefactStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders a drawing's page previews, alongside the analysis rather than before it.
 *
 * `pdftoppm` costs about a second on a normal drawing and more on a large set, and
 * that second used to sit on the run's critical path for no reason: nothing in the
 * analysis reads a preview. They are read later, by the review screen, the drawing
 * details screen and the annotated export.
 *
 * Queued separately so it runs on another worker while the engine is still
 * thinking. With a single worker it simply runs before or after — never worse than
 * rendering inline.
 */
class RenderDrawingPreviews implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public readonly int $uploadId) {}

    public function handle(ArtefactStore $store): void
    {
        $upload = Upload::find($this->uploadId);

        if (! $upload) {
            return;
        }

        try {
            $began = microtime(true);
            $rendered = $store->renderPreviews($upload);

            Log::info('Drawing previews rendered', [
                'upload_id' => $upload->id,
                'pages' => $rendered['pages'],
                'render_ms' => round((microtime(true) - $began) * 1000, 1),
            ]);
        } catch (Throwable $e) {
            // Previews are a convenience; the screens fall back to a placeholder.
            Log::warning('Drawing previews could not be rendered', [
                'upload_id' => $upload->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
