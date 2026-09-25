<?php

namespace App\Services\Ai;

use App\Models\AiResult;
use App\Models\SymbolReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Enriches a run with the engine's per-crop lifecycle detail.
 *
 * `GET /api/debug/lifecycle/{run_id}` is where the bounding boxes, page numbers,
 * detector, stage trail, final decision and crop images live — everything a review
 * card shows beyond the aggregated counts. All of it is read over HTTP.
 *
 * The upload response carries its own `run_id` (`AiResponseNormaliser` reads it
 * onto `ai_results.run_id`), which is the normal path: `fetch()` asks the engine
 * for that exact run directly, no shared filesystem involved. `resolve()` below —
 * newest run directory whose lifecycle `project_name` matches the drawing just
 * analysed — is only a fallback for a run ingested before that was captured, and
 * needs a shared filesystem with the engine, which is why it stays optional:
 * without it, ingest still completes from the upload response alone and cards
 * simply carry no crop image.
 */
class LifecycleReader
{
    public function __construct(
        private readonly AiTakeoffClient $client,
        private readonly ArtefactStore $store,
    ) {}

    public function enabled(): bool
    {
        // Static takeoff mode answers from a stored dataset, never the real
        // engine — the lifecycle/debug endpoint is read straight off
        // `AiTakeoffClient`, not through the `TakeoffEngine` contract, so this
        // is the one place that has to know about it to guarantee no dynamic
        // engine call happens while static mode is on.
        if (config('static_takeoff.enabled')) {
            return false;
        }

        return (bool) config('ai.lifecycle.enabled');
    }

    /**
     * Finds the engine run that produced this analysis.
     *
     * @return array{run_id: string, lifecycle: array<string, mixed>}|null
     */
    public function resolve(string $projectName): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        foreach ($this->candidateRunIds() as $runId) {
            try {
                $lifecycle = $this->client->lifecycle($runId);
            } catch (AiApiException) {
                continue;
            }

            if ($this->matches($lifecycle['project_name'] ?? null, $projectName)) {
                return ['run_id' => $runId, 'lifecycle' => $lifecycle];
            }
        }

        return null;
    }

    /**
     * The engine's lifecycle document for a known run.
     *
     * @return array<string, mixed>
     */
    public function fetch(string $runId): array
    {
        return $this->client->lifecycle($runId);
    }

    /**
     * Attaches crop detail to the run's review rows and files the crop images.
     *
     * @param  array<string, mixed>  $lifecycle
     * @return int Number of review rows enriched.
     */
    public function attach(AiResult $result, array $lifecycle): int
    {
        $crops = $this->crops($lifecycle);

        if ($crops->isEmpty()) {
            return 0;
        }

        // Recorded up front: filing crop images needs the run id.
        $result->update([
            'run_id' => $lifecycle['run_id'] ?? $result->run_id,
            'lifecycle_statistics' => is_array($lifecycle['statistics'] ?? null)
                ? $lifecycle['statistics']
                : null,
        ]);

        $byName = $crops->groupBy(fn (array $crop) => Str::lower($crop['candidate_name']));

        /*
         * Matched first, fetched second, written third.
         *
         * These used to be interleaved, which made the loop one HTTP round trip per
         * symbol — the run's slowest stage by far on a busy drawing. Pairing every
         * review with its crop up front means the images can all be asked for at
         * once, and the writes can share a single transaction instead of committing
         * hundreds of times.
         */
        $matched = [];

        foreach ($result->reviews()->get() as $review) {
            // A needs-review card names its own image; a symbol card borrows the
            // most confident crop that produced it.
            $crop = $review->image_path
                ? $crops->firstWhere('image_path', $review->image_path)
                : $byName->get(Str::lower($review->ai_name), collect())
                    ->sortByDesc('confidence')
                    ->first();

            if ($crop) {
                $matched[$review->id] = ['review' => $review, 'crop' => $crop];
            }
        }

        if ($matched === []) {
            return 0;
        }

        $images = $this->fetchImages($result, $matched);

        DB::transaction(function () use ($matched, $byName, $images) {
            foreach ($matched as $id => ['review' => $review, 'crop' => $crop]) {
                $review->update([
                    'crop_id' => $crop['crop_id'],
                    'image_path' => $review->image_path ?: $crop['image_path'],
                    'page' => $review->page ?: $crop['page'],
                    'bbox' => $crop['bbox'],
                    'stages' => $crop['stages'],
                    'final_decision' => $crop['final_decision'],
                    'detection_source' => $review->detection_source ?: $crop['detection_source'],
                    'pipeline' => $this->pipelineFlags($crop['stages']),
                    'crop_count' => $byName->get(Str::lower($review->ai_name), collect())->count(),
                    'crop_path' => $images[$id] ?? null,
                ]);
            }
        });

        return count($matched);
    }

    /**
     * The engine's real per-page raster dimensions for a run, keyed by page
     * number — the only reliable source for mapping a bbox onto a drawing
     * page. Returns an empty array (never a guess) when the engine has
     * nothing recorded for this run, e.g. its debug artefacts have expired.
     *
     * @return array<int, array{width: float, height: float}>
     */
    public function pageSizes(string $runId): array
    {
        if (! $this->enabled()) {
            return [];
        }

        try {
            $info = $this->client->pageInfo($runId);
        } catch (AiApiException) {
            return [];
        }

        if (($info['available'] ?? false) !== true || ! is_array($info['sizes'] ?? null)) {
            return [];
        }

        return collect($info['sizes'])
            ->mapWithKeys(fn ($size, $page) => is_array($size) && ($size['w'] ?? 0) > 0 && ($size['h'] ?? 0) > 0
                ? [(int) $page => ['width' => (float) $size['w'], 'height' => (float) $size['h']]]
                : [])
            ->all();
    }

    /* ------------------------------------------------------------- internals */

    /**
     * Run ids worth checking: the engine's most recent runs, newest first.
     *
     * @return list<string>
     */
    private function candidateRunIds(): array
    {
        $directory = config('ai.lifecycle.directory');

        if (blank($directory) || ! is_dir($directory)) {
            return [];
        }

        $cutoff = time() - max(60, (int) config('ai.lifecycle.max_age_seconds'));

        return collect(glob(rtrim((string) $directory, '/').'/*', GLOB_ONLYDIR) ?: [])
            ->map(fn (string $path) => ['id' => basename($path), 'time' => (int) @filemtime($path)])
            ->filter(fn (array $run) => $run['time'] >= $cutoff)
            ->sortByDesc('time')
            ->pluck('id')
            ->take(5)
            ->values()
            ->all();
    }

    private function matches(mixed $lifecycleProject, string $projectName): bool
    {
        if (blank($lifecycleProject)) {
            return false;
        }

        $left = Str::lower(trim((string) $lifecycleProject));
        $right = Str::lower(trim($projectName));

        // The engine names a run after either the file or the title block it read.
        return $left === $right
            || str_contains($left, $right)
            || str_contains($right, $left);
    }

    /**
     * @param  array<string, mixed>  $lifecycle
     * @return Collection<int, array<string, mixed>>
     */
    private function crops(array $lifecycle): Collection
    {
        return collect($lifecycle['crops'] ?? [])
            ->filter(fn ($crop) => is_array($crop) && filled($crop['candidate_name'] ?? null))
            ->map(fn (array $crop) => [
                'crop_id' => (string) ($crop['crop_id'] ?? ''),
                'image_path' => (string) ($crop['image_path'] ?? ''),
                'page' => max(1, (int) ($crop['page'] ?? 1)),
                'candidate_name' => (string) $crop['candidate_name'],
                'confidence' => (float) ($crop['confidence'] ?? 0),
                'bbox' => $this->bbox($crop['bounding_box'] ?? null),
                'stages' => $this->stages($crop['stages'] ?? []),
                'final_decision' => (string) ($crop['final_decision'] ?? ''),
                'detection_source' => (string) ($crop['detection_source'] ?? ''),
            ])
            ->values();
    }

    /**
     * `{x, y, w, h}` flattened to the `[x, y, width, height]` the UI draws.
     *
     * @return list<float>|null
     */
    private function bbox(mixed $box): ?array
    {
        if (! is_array($box) || ! isset($box['x'], $box['y'])) {
            return null;
        }

        return [
            round((float) $box['x'], 2),
            round((float) $box['y'], 2),
            round((float) ($box['w'] ?? $box['width'] ?? 0), 2),
            round((float) ($box['h'] ?? $box['height'] ?? 0), 2),
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, status: string}>
     */
    private function stages($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($stage) => is_array($stage) && filled($stage['name'] ?? null))
            ->map(fn (array $stage) => [
                'name' => (string) $stage['name'],
                'status' => (string) ($stage['status'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * The stage trail as the boolean map the review card renders.
     *
     * @param  list<array{name: string, status: string}>  $stages
     * @return array<string, bool>
     */
    private function pipelineFlags(array $stages): array
    {
        return collect($stages)
            ->mapWithKeys(fn (array $stage) => [
                Str::of($stage['name'])->lower()->replace(' ', '_')->value() => $stage['status'] === 'green',
            ])
            ->all();
    }

    /**
     * Files every matched crop image on our own disk, in one concurrent sweep.
     *
     * Held locally so review cards never depend on the engine being reachable and
     * the run keeps a complete artefact set.
     *
     * @param  array<int, array{review: SymbolReview, crop: array<string, mixed>}>  $matched
     * @return array<int, string|null> Stored path per review id.
     */
    private function fetchImages(AiResult $result, array $matched): array
    {
        $runId = $result->run_id;

        if (blank($runId)) {
            return [];
        }

        $wanted = [];

        foreach ($matched as $id => ['crop' => $crop]) {
            if (filled($crop['image_path'])) {
                $wanted[$id] = $crop['image_path'];
            }
        }

        try {
            $bytes = $this->client->lifecycleImages($runId, $wanted);
        } catch (Throwable $e) {
            Log::warning('Crop images could not be fetched', [
                'ai_result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $paths = [];

        foreach ($bytes as $id => $content) {
            if ($content === null) {
                continue;
            }

            $review = $matched[$id]['review'];

            try {
                $paths[$id] = $this->store->put(
                    $result->project,
                    'crops/'.Str::slug($review->external_id ?: 'crop-'.$review->id).'.png',
                    $content,
                );
            } catch (Throwable $e) {
                Log::warning('Crop image could not be filed', [
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $paths;
    }
}
