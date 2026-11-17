<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\TakeoffEngine;
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
 * Extraction is asynchronous on the engine (`POST /api/v1/electrical/extract` →
 * poll `/status` → `GET /result`), so `analyse()` blocks while polling — and only
 * ever on a queue worker, never in an HTTP request.
 *
 * Every call is bounded twice: a short connect timeout so an unreachable engine
 * fails fast instead of holding a request open, and a per-call request timeout.
 * Idempotent reads retry with exponential backoff; the analysis never retries
 * inside one call, because repeating it is expensive and the queued job owns that
 * decision.
 */
class AiTakeoffClient implements TakeoffEngine
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

            $accepted = $this->decode($this->send(
                fn () => $request->post($this->url($this->config('endpoints.extract'))),
                'submitting the drawing',
            ));

            $jobId = (string) ($accepted['job_id'] ?? '');

            if ($jobId === '') {
                throw AiApiException::unusablePayload('the engine did not return a job_id.');
            }

            $this->waitForCompletion($jobId);

            return $this->decode($this->send(
                fn () => $this->request($this->config('timeout.analyse'))
                    ->get($this->url($this->config('endpoints.result'), ['job' => $jobId])),
                'reading the extraction result',
            ));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * The engine's symbol library, searched by text. Returns image-bearing symbols
     * as `[id, label]` pairs, best match first.
     *
     * @return list<array{id: string, label: string}>
     */
    public function searchSymbols(string $term, int $limit = 5): array
    {
        if (! $this->isConfigured() || blank($term)) {
            return [];
        }

        try {
            $response = $this->request($this->config('timeout.read'))
                ->get($this->url($this->config('endpoints.symbols')), [
                    'q' => $term,
                    'has_image' => 'true',
                    'limit' => $limit,
                ]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('items', []))
            ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null))
            ->map(fn (array $item) => ['id' => (string) $item['id'], 'label' => (string) ($item['legend_label'] ?? $item['description'] ?? '')])
            ->values()
            ->all();
    }

    /**
     * Legend symbol images, fetched concurrently.
     *
     * @param  list<string>  $symbolIds  Engine symbol (variant) ids.
     * @return array<string, array{body: string, extension: string}> Keyed by id; failures are absent.
     */
    public function symbolImages(array $symbolIds): array
    {
        if (! $this->isConfigured() || $symbolIds === []) {
            return [];
        }

        $endpoint = $this->config('endpoints.symbol_image');
        $images = [];

        foreach (array_chunk(array_values($symbolIds), 25) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $this->applyOptions($pool->as($id), $this->config('timeout.read'))
                    ->get($this->url($endpoint, ['symbol' => $id])),
                $chunk,
            ));

            foreach ($chunk as $id) {
                $response = $responses[$id] ?? null;

                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $type = strtolower((string) $response->header('Content-Type'));

                $images[$id] = [
                    'body' => $response->body(),
                    'extension' => match (true) {
                        str_contains($type, 'svg') => 'svg',
                        str_contains($type, 'jpeg') => 'jpg',
                        str_contains($type, 'webp') => 'webp',
                        default => 'png',
                    },
                ];
            }
        }

        return $images;
    }

    /*
     * The new engine has no per-run lifecycle, page-info or crop-image endpoints:
     * crops are rendered locally from each detection's normalised bounding box.
     * These remain only so results ingested from the previous engine (which carry
     * a run_id) degrade to "no crop detail" instead of failing.
     */

    /** @return array<string, mixed> */
    public function lifecycle(string $runId): array
    {
        throw AiApiException::unusablePayload('this engine has no lifecycle endpoint.');
    }

    /** @return array<string, mixed> */
    public function pageInfo(string $runId): array
    {
        return ['available' => false];
    }

    public function lifecycleImage(string $runId, string $imagePath): ?string
    {
        return null;
    }

    /**
     * @param  array<string, string>  $imagePaths
     * @return array<string, string|null>
     */
    public function lifecycleImages(string $runId, array $imagePaths): array
    {
        return array_fill_keys(array_keys($imagePaths), null);
    }

    public function reviewImage(string $runId, string $imageId): ?string
    {
        return null;
    }

    /**
     * Polls the job's status until it completes. Throws with the engine's own
     * message when the job fails, or when the overall wait exceeds the budget.
     */
    private function waitForCompletion(string $jobId): void
    {
        $deadline = microtime(true) + (float) $this->config('timeout.analyse');
        $interval = max(0.2, (float) $this->config('polling.interval_seconds'));

        while (true) {
            $status = $this->decode($this->send(
                fn () => $this->request($this->config('timeout.read'))
                    ->get($this->url($this->config('endpoints.status'), ['job' => $jobId])),
                'checking the extraction status',
            ));

            $state = (string) ($status['status'] ?? '');

            if ($state === 'completed') {
                return;
            }

            if ($state === 'failed') {
                throw new AiApiException(
                    (string) ($status['error_message'] ?? $status['developer_error_message'] ?? 'The AI engine failed to extract this drawing.')
                );
            }

            if (microtime(true) >= $deadline) {
                throw new AiApiException('The AI engine did not finish the drawing in time.');
            }

            usleep((int) ($interval * 1_000_000));
        }
    }

    /**
     * Pushes a reviewer decision to `POST /api/v1/electrical/knowledge/feedback`.
     *
     * Best-effort: the decision is already committed locally, so a failure here
     * is reported precisely rather than thrown.
     *
     * @param  array<string, mixed>  $payload  KnowledgeFeedbackRequest fields.
     * @return array{delivered: bool, status: ?int, detail: ?string}
     */
    public function pushDecision(array $payload): array
    {
        if (! $this->isConfigured()) {
            return ['delivered' => false, 'status' => null, 'detail' => 'the engine is not configured'];
        }

        try {
            $response = $this->request($this->config('timeout.read'))
                ->asJson()
                ->post($this->url($this->config('endpoints.feedback')), $payload);

            if ($response->successful()) {
                return ['delivered' => true, 'status' => $response->status(), 'detail' => null];
            }

            $detail = $response->json('error_message') ?? $response->json('detail') ?? str($response->body())->limit(160)->value();

            Log::info('The AI engine declined a review decision', [
                'status' => $response->status(),
                'detail' => $detail,
            ]);

            return ['delivered' => false, 'status' => $response->status(), 'detail' => is_string($detail) ? $detail : json_encode($detail)];
        } catch (ConnectionException $e) {
            Log::warning('AI engine unreachable while pushing a review decision', ['error' => $e->getMessage()]);

            return ['delivered' => false, 'status' => null, 'detail' => 'the engine could not be reached'];
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
