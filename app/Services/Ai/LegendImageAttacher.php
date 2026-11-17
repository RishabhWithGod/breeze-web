<?php

namespace App\Services\Ai;

use App\Models\AiResult;
use App\Models\SymbolReview;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives each review card the real symbol image the engine extracted from the
 * drawing's legend.
 *
 * The extraction response lists `legend_symbols`, each with an `image_url`
 * (`/api/v1/electrical/symbols/{id}/image`). A component is tied to its legend
 * row by symbol code, then by name/type/description. The image is copied onto
 * our own disk and set as the card's `crop_path`, so the review screen serves it
 * like any other crop and never calls the engine while a page renders.
 *
 * Decoration only: a card with no matching legend row, or an image that cannot
 * be fetched, simply keeps its placeholder.
 */
class LegendImageAttacher
{
    public function __construct(
        private readonly AiTakeoffClient $client,
        private readonly ArtefactStore $store,
    ) {}

    /** @return int Cards that received an image. */
    public function attach(AiResult $result): int
    {
        $payload = $result->original_payload ?? [];
        $legend = collect($payload['legend_symbols'] ?? [])
            ->filter(fn ($row) => is_array($row) && ($row['has_image'] ?? false) && filled($row['symbol_variant_id'] ?? null))
            ->values();

        if (! $this->client->isConfigured()) {
            return 0;
        }

        $reviews = $result->reviews()
            ->where('origin', SymbolReview::ORIGIN_SYMBOL)
            ->whereNull('crop_path')
            ->get();

        // card key → legend symbol id
        $wanted = [];
        $components = collect($payload['components'] ?? [])->filter(fn ($c) => is_array($c));

        foreach ($reviews as $review) {
            $component = $components->first(
                fn ($c) => Str::lower($this->flat($c['name'] ?? '')) === Str::lower($this->flat($review->ai_name)),
            );

            if ($component === null) {
                continue;
            }

            $id = $this->legendFor($component, $legend->all())['symbol_variant_id']
                ?? $this->libraryMatch($component);

            if ($id !== null) {
                $wanted[$review->id] = (string) $id;
            }
        }

        if ($wanted === []) {
            return 0;
        }

        $images = $this->client->symbolImages(array_values(array_unique($wanted)));
        $attached = 0;

        foreach ($reviews as $review) {
            $image = $images[$wanted[$review->id] ?? ''] ?? null;

            if ($image === null) {
                continue;
            }

            try {
                $path = $this->store->put(
                    $result->project,
                    'crops/'.Str::slug($review->external_id ?: 'symbol-'.$review->id).'.'.$image['extension'],
                    $image['body'],
                );

                $review->update(['crop_path' => $path]);
                $attached++;
            } catch (\Throwable $e) {
                Log::warning('Legend symbol image could not be filed', [
                    'review_id' => $review->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $attached;
    }

    /**
     * Falls back to the engine's learned symbol library when this drawing's own
     * legend has no row for the component. Only an exact label match is taken — a
     * near match would put the wrong picture on a card.
     *
     * @param  array<string, mixed>  $component
     */
    private function libraryMatch(array $component): ?string
    {
        $keys = array_filter([$this->flat($component['type'] ?? ''), $this->flat($component['name'] ?? '')]);

        foreach (array_filter([$component['type'] ?? null, $component['name'] ?? null]) as $term) {
            foreach ($this->client->searchSymbols((string) $term) as $hit) {
                if (in_array($this->flat($hit['label']), $keys, true)) {
                    return $hit['id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  list<array<string, mixed>>  $legend
     * @return array<string, mixed>|null
     */
    private function legendFor(array $component, array $legend): ?array
    {
        $keys = array_values(array_filter([
            $this->flat($component['symbol'] ?? ''),
            $this->flat($component['name'] ?? ''),
            $this->flat($component['type'] ?? ''),
        ]));

        // Exact on the symbol code or description first, then a looser contains.
        foreach ($legend as $row) {
            $candidates = array_filter([$this->flat($row['symbol'] ?? ''), $this->flat($row['description'] ?? '')]);

            if (array_intersect($keys, $candidates) !== []) {
                return $row;
            }
        }

        foreach ($legend as $row) {
            $description = $this->flat($row['description'] ?? '');

            foreach ($keys as $key) {
                if (strlen($key) >= 6 && $description !== '' && (str_contains($description, $key) || str_contains($key, $description))) {
                    return $row;
                }
            }
        }

        return null;
    }

    private function flat(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', ' ', Str::lower(trim((string) $value))) ?? '';
    }
}
