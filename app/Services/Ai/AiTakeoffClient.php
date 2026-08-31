<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transport for the Electrical Drawing AI Takeoff Engine.
 *
 * The only class that speaks HTTP to the engine — everything above it deals in
 * arrays. Endpoint paths, auth style and timeouts come from config/ai.php, so a
 * relocated or gateway-fronted engine needs no code change.
 *
 * `POST /api/upload` is synchronous: it returns the entire AnalysisResult, which
 * is why `analyse()` runs under a minutes-long timeout — and only ever on a queue
 * worker, never in an HTTP request.
 *
 * Every call is bounded twice: a short connect timeout so an unreachable engine
 * fails fast instead of holding a request open, and a per-call request timeout.
 * Idempotent reads retry with exponential backoff; the analysis never retries
 * inside one call, because repeating it is expensive and the queued job owns that
 * decision.
 */
class AiTakeoffClient
{
    /**
     * Analyses a drawing. Returns the engine's AnalysisResult verbatim.
     *
     * @return array<string, mixed>
     */
    public function analyse(string $absolutePath, string $fileName): array
    {
        $this->assertConfigured();

        // Streamed rather than read into memory: drawing sets reach 100 MB.
        $handle = fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw AiApiException::unusablePayload("the drawing at {$absolutePath} could not be opened.");
        }

        try {
            $request = $this->request($this->config('timeout.analyse'), retry: false)
                ->attach(
                    (string) config('ai.file_field'),
                    $handle,
                    $fileName,
                    ['Content-Type' => 'application/pdf'],
                );

            return $this->decode($this->send(
                fn () => $request->post($this->url($this->config('endpoints.upload'))),
                'analysing the drawing',
            ));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Per-crop detail for a run: bounding boxes, pages, detector, pipeline stages
     * and the crop image paths the review cards render.
     *
     * @return array<string, mixed>
     */
    public function lifecycle(string $runId): array
    {
        $this->assertConfigured();

        return $this->decode($this->send(
            fn () => $this->request($this->config('timeout.read'))
                ->get($this->url($this->config('endpoints.lifecycle'), ['run' => $runId])),
            'reading the run lifecycle',
        ));
    }

    /**
     * The engine's own per-page raster size for a run — the exact pixel space
     * its detection coordinates were reported in, plus the DPI it rendered at.
     * This is the only reliable source for mapping a bbox onto a drawing page;
     * nothing else the engine returns carries page dimensions.
     *
     * @return array<string, mixed>
     */
    public function pageInfo(string $runId): array
    {
        $this->assertConfigured();

        return $this->decode($this->send(
            fn () => $this->request($this->config('timeout.read'))
                ->get($this->url($this->config('endpoints.page_info'), ['run' => $runId])),
            'reading the page dimensions',
        ));
    }

    /** Raw bytes of a lifecycle crop image, or null when it cannot be fetched. */
    public function lifecycleImage(string $runId, string $imagePath): ?string
    {
        return $this->fetchImage(
            $this->url($this->config('endpoints.lifecycle_image'), [
                'run' => $runId,
                'image' => $imagePath,
            ])
        );
    }

    /**
     * Many crop images at once, fetched concurrently.
     *
     * One request per symbol, in sequence, is the single slowest thing this client
     * used to do: a drawing with two hundred detections meant two hundred round
     * trips end to end. They are independent reads of static files, so they go out
     * together and the run waits once instead of two hundred times.
     *
     * @param  array<string, string>  $imagePaths  Engine image paths, keyed by caller reference.
     * @return array<string, string|null> Raw bytes keyed the same way; null where a fetch failed.
     */
    public function lifecycleImages(string $runId, array $imagePaths): array
    {
        if (! $this->isConfigured() || $imagePaths === []) {
            return array_fill_keys(array_keys($imagePaths), null);
        }

        $endpoint = $this->config('endpoints.lifecycle_image');
        $fetched = [];

        /*
         * Chunked so a large run cannot open hundreds of sockets at once — the
         * engine is a single FastAPI process and would queue them anyway.
         */
        foreach (array_chunk($imagePaths, 25, true) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string|int $key) => $this->applyOptions($pool->as((string) $key), $this->config('timeout.read'))
                    ->get($this->url($endpoint, ['run' => $runId, 'image' => $chunk[$key]])),
                array_keys($chunk),
            ));

            foreach (array_keys($chunk) as $key) {
                $response = $responses[$key] ?? null;

                $fetched[$key] = $response instanceof Response && $response->successful()
                    ? $response->body()
                    : null;
            }
        }

        return $fetched;
    }

    /** Raw bytes of a needs-review crop image, or null. */
    public function reviewImage(string $runId, string $imageId): ?string
    {
        return $this->fetchImage(
            $this->url($this->config('endpoints.review_image'), [
                'run' => $runId,
                'image' => $imageId,
            ])
        );
    }

    /**
     * Pushes a reviewer decision back into the engine's symbol library.
     *
     * The learning loop is best-effort: a failure here must never block a review.
     * The outcome is reported precisely rather than as a bare boolean, because
     * "the engine declined" (e.g. a symbol it has never seen) and "the engine is
     * down" mean different things in an audit trail.
     *
     * @param  'approve'|'reject'|'rename'|'merge'  $action
     * @param  array<string, mixed>  $payload
     * @return array{delivered: bool, status: ?int, detail: ?string}
     */
    public function pushDecision(string $action, array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['delivered' => false, 'status' => null, 'detail' => 'the engine is not configured'];
        }

        try {
            $response = $this->request($this->config('timeout.read'))
                ->asJson()
                ->post($this->url($this->config("endpoints.{$action}")), $payload);

            if ($response->successful()) {
                return ['delivered' => true, 'status' => $response->status(), 'detail' => null];
            }

            // The engine explains itself in `detail`; carry that through verbatim.
            $detail = $response->json('detail') ?? str($response->body())->limit(160)->value();

            Log::info('The AI engine declined a review decision', [
                'action' => $action,
                'status' => $response->status(),
                'detail' => $detail,
            ]);

            return ['delivered' => false, 'status' => $response->status(), 'detail' => (string) $detail];
        } catch (ConnectionException $e) {
            Log::warning('AI engine unreachable while pushing a review decision', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'delivered' => false,
                'status' => null,
                'detail' => 'the engine could not be reached',
            ];
        }
    }

    /**
     * Engine health, for the upload screen's readiness indicator.
     *
     * @return array{ok: bool, detail: array<string, mixed>|null}
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => null];
        }

        /*
         * Cached, because this is polled by the upload screen and asked again during
         * ingest. Without the cache an unreachable engine would cost every caller the
         * full connect timeout.
         */
        return Cache::remember(
            'ai:engine-health:'.md5((string) config('ai.base_url')),
            (int) config('ai.health_cache_seconds'),
            function (): array {
                try {
                    $response = $this->request($this->config('timeout.health'), retry: false)
                        ->get($this->url($this->config('endpoints.health')));

                    return [
                        'ok' => $response->successful(),
                        'detail' => $response->successful() ? $response->json() : null,
                    ];
                } catch (ConnectionException) {
                    return ['ok' => false, 'detail' => null];
                }
            },
        );
    }

    public function isConfigured(): bool
    {
        return filled(config('ai.base_url'));
    }

    /* ------------------------------------------------------------- internals */

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw AiApiException::notConfigured();
        }
    }

    /**
     * A configured request.
     *
     * @param  bool  $retry  Idempotent reads retry with exponential backoff; the
     *                       analysis upload does not.
     */
    private function request(int|float $timeout, bool $retry = true): PendingRequest
    {
        return $this->applyOptions(Http::acceptJson(), $timeout, $retry);
    }

    /**
     * Timeouts, retries and auth, applied to a request this class did not create.
     *
     * Split out because a pooled request comes from the pool, not from `Http::`, and
     * it must still be bounded and authenticated exactly like every other call.
     */
    private function applyOptions(PendingRequest $request, int|float $timeout, bool $retry = false): PendingRequest
    {
        $request->connectTimeout((float) $this->config('timeout.connect'))->timeout($timeout);

        if ($retry) {
            $base = max(50, (int) $this->config('retries.base_sleep_ms'));

            $request->retry(
                max(1, (int) $this->config('retries.times')),
                // Exponential backoff: 200ms, 400ms, 800ms…
                fn (int $attempt) => $base * (2 ** ($attempt - 1)),
                throw: false,
            );
        }

        $key = config('ai.key');

        if (blank($key)) {
            return $request;
        }

        return config('ai.auth.mode') === 'header'
            ? $request->withHeaders([config('ai.auth.header') => $key])
            : $request->withToken($key);
    }

    /**
     * Runs a call, turning transport failures and error statuses into
     * AiApiException so callers only handle one failure type.
     *
     * @param  callable(): Response  $call
     */
    private function send(callable $call, string $action): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new AiApiException(
                "The AI takeoff engine could not be reached while {$action}.",
                previous: $e,
            );
        }

        if ($response->failed()) {
            Log::error('AI engine request failed', [
                'action' => $action,
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->value(),
            ]);

            throw AiApiException::badStatus($action, $response->status(), $response->body());
        }

        return $response;
    }

    /** Images are optional decoration, so failures return null rather than throw. */
    private function fetchImage(string $url): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->request($this->config('timeout.read'))->get($url);

            return $response->successful() ? $response->body() : null;
        } catch (ConnectionException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw AiApiException::unusablePayload('the body was not a JSON object.');
        }

        return $payload;
    }

    /** @param  array<string, string>  $replacements */
    private function url(string $path, array $replacements = []): string
    {
        foreach ($replacements as $token => $value) {
            // Image paths contain slashes the engine expects unescaped.
            $encoded = $token === 'image'
                ? implode('/', array_map('rawurlencode', explode('/', $value)))
                : rawurlencode($value);

            $path = str_replace(":{$token}", $encoded, $path);
        }

        return rtrim((string) config('ai.base_url'), '/').'/'.ltrim($path, '/');
    }

    private function config(string $key): mixed
    {
        return config("ai.{$key}");
    }
}
