<?php

namespace App\Services\Ai;

use App\Models\SymbolReview;
use Illuminate\Support\Str;

/**
 * Maps the engine's `AnalysisResult` onto the rows this application reviews.
 *
 * The response is stored untouched; this class only reads it. Every documented
 * field is carried through — nothing is dropped:
 *
 *   project_name, pages, symbol_counts, symbols, known_symbols, unknown_symbols,
 *   rejected_symbols, needs_review, panel_schedules, equipment, wire_sizes,
 *   circuits, boq, estimate, warnings, processing_time, pipeline_status
 *
 * `symbols` are aggregated per symbol type, so one review card is one symbol type
 * carrying its whole count. `needs_review` observations become their own cards:
 * the engine has not counted them, so they start life rejected-by-default and only
 * enter the takeoff if a reviewer approves them.
 */
class AiResponseNormaliser
{
    /** Detector names the engine reports, mapped onto the table's columns. */
    private const SOURCE_COLUMNS = [
        'template' => 'source_template',
        'vector' => 'source_vector',
        'vision' => 'source_vision',
        'ocr' => 'source_ocr',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     project_name: ?string,
     *     pages: int,
     *     symbol_counts: array<string, int>,
     *     cards: list<array<string, mixed>>,
     *     panel_schedules: list<array<string, mixed>>,
     *     equipment: list<array<string, mixed>>,
     *     wire_sizes: list<array<string, mixed>>,
     *     circuits: list<array<string, mixed>>,
     *     boq: list<array<string, mixed>>,
     *     estimate: array<string, mixed>,
     *     warnings: list<string>,
     *     pipeline_status: array<string, string>,
     *     processing_time: float,
     *     confidence: float,
     *     item_total: int,
     * }
     */
    public function normalise(array $payload): array
    {
        $cards = $this->cards($payload);

        if ($cards === []) {
            throw AiApiException::unusablePayload(
                'it contained no symbols and nothing flagged for review.'
            );
        }

        return [
            'project_name' => $this->string($payload['project_name'] ?? null),
            'pages' => max(0, (int) ($payload['pages'] ?? 0)),
            'symbol_counts' => $this->symbolCounts($payload),
            'cards' => $cards,

            'panel_schedules' => $this->panelSchedules($payload),
            'equipment' => $this->equipment($payload),
            'wire_sizes' => $this->wireSizes($payload),
            'circuits' => $this->circuits($payload),
            'boq' => $this->boq($payload),
            'estimate' => $this->estimate($payload),

            'warnings' => $this->warnings($payload),
            'pipeline_status' => $this->pipelineStatus($payload),
            'processing_time' => round((float) ($payload['processing_time'] ?? 0), 3),

            'confidence' => $this->overallConfidence($cards),
            'item_total' => (int) collect($cards)
                ->where('origin', SymbolReview::ORIGIN_SYMBOL)
                ->sum('count'),
        ];
    }

    /**
     * One card per symbol type, plus one per needs-review observation.
     *
     * `known_symbols` / `unknown_symbols` / `rejected_symbols` are the engine's
     * classification of the same entries in `symbols`, so they are folded in as
     * category rather than duplicated as extra cards.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function cards(array $payload): array
    {
        $known = $this->nameSet($payload['known_symbols'] ?? []);
        $unknown = $this->nameSet($payload['unknown_symbols'] ?? []);
        $rejected = $this->nameSet($payload['rejected_symbols'] ?? []);

        $cards = [];
        $position = 0;

        foreach ($this->summaries($payload['symbols'] ?? []) as $summary) {
            $key = Str::lower($summary['name']);

            $category = match (true) {
                isset($rejected[$key]) => SymbolReview::CATEGORY_REJECTED,
                isset($unknown[$key]) => SymbolReview::CATEGORY_UNKNOWN,
                isset($known[$key]) => SymbolReview::CATEGORY_KNOWN,
                default => SymbolReview::CATEGORY_KNOWN,
            };

            $cards[] = [
                ...$summary,
                'external_id' => Str::slug($summary['name']) ?: 'symbol-'.($position + 1),
                'origin' => SymbolReview::ORIGIN_SYMBOL,
                'ai_category' => $category,
                'reason' => $category === SymbolReview::CATEGORY_REJECTED
                    ? 'Rejected by the engine’s symbol library'
                    : null,
                'is_known' => $category === SymbolReview::CATEGORY_KNOWN,
                'page' => null,
                'image_id' => null,
                'image_path' => null,
                // The engine already counted these, so they arrive approved and a
                // reviewer only has to overturn what looks wrong. Anything it
                // rejected starts rejected.
                'status' => $category === SymbolReview::CATEGORY_REJECTED
                    ? SymbolReview::STATUS_REJECTED
                    : SymbolReview::STATUS_APPROVED,
                'position' => $position++,
            ];
        }

        foreach ($this->needsReview($payload['needs_review'] ?? []) as $item) {
            $cards[] = [
                ...$item,
                'external_id' => ($item['image_id'] ?: Str::slug($item['name'])).'-review',
                'origin' => SymbolReview::ORIGIN_NEEDS_REVIEW,
                'ai_category' => SymbolReview::CATEGORY_NEEDS_REVIEW,
                'is_known' => false,
                // Not in the engine's counts: it takes an approval to bring it in.
                'status' => SymbolReview::STATUS_REJECTED,
                'position' => $position++,
            ];
        }

        return $cards;
    }

    /**
     * Lower-cased names of a `SymbolSummary` list, for classifying `symbols`
     * against `known_symbols` / `unknown_symbols` / `rejected_symbols`.
     *
     * @param  mixed  $raw
     * @return array<string, true>
     */
    private function nameSet($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->mapWithKeys(fn (array $item) => [Str::lower($this->name($item['name'])) => true])
            ->all();
    }

    /**
     * `SymbolSummary` rows: name, count, confidence, sources, evidence.
     *
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function summaries($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->map(fn (array $item) => [
                'name' => $this->name($item['name']),
                'count' => max(0, (int) ($item['count'] ?? 0)),
                'confidence' => $this->confidence($item['confidence'] ?? 0),
                'sources' => $this->sources($item['sources'] ?? []),
                'evidence' => $this->labels($item['evidence'] ?? []),
                'detection_source' => $this->labels($item['sources'] ?? [])[0] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * `NeedsReviewItem` rows: name, count, confidence, source, reason, page,
     * image_id, image_path.
     *
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function needsReview($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->map(function (array $item) {
                $source = $this->string($item['source'] ?? null);

                return [
                    'name' => $this->name($item['name']),
                    'count' => max(0, (int) ($item['count'] ?? 0)),
                    'confidence' => $this->confidence($item['confidence'] ?? 0),
                    'sources' => $this->sources($source === null ? [] : [$source]),
                    'evidence' => array_values(array_filter([$source])),
                    'detection_source' => $source,
                    'reason' => $this->string($item['reason'] ?? null),
                    'page' => isset($item['page']) ? (int) $item['page'] : null,
                    'image_id' => $this->string($item['image_id'] ?? null),
                    'image_path' => $this->string($item['image_path'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>
     */
    private function symbolCounts(array $payload): array
    {
        $counts = $payload['symbol_counts'] ?? [];

        if (! is_array($counts)) {
            return [];
        }

        return collect($counts)
            ->mapWithKeys(fn ($count, $name) => [(string) $name => (int) $count])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function panelSchedules(array $payload): array
    {
        return $this->rows($payload['panel_schedules'] ?? [], fn (array $item, int $index) => [
            'page' => max(0, (int) ($item['page'] ?? 0)),
            'panel_name' => (string) ($item['panel_name'] ?? ''),
            'rows' => is_array($item['rows'] ?? null) ? $item['rows'] : [],
            'raw_headers' => $this->labels($item['raw_headers'] ?? []),
            'position' => $index,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function equipment(array $payload): array
    {
        return $this->rows($payload['equipment'] ?? [], fn (array $item, int $index) => [
            'page' => max(0, (int) ($item['page'] ?? 0)),
            'tag' => (string) ($item['tag'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'rating' => (string) ($item['rating'] ?? ''),
            'quantity' => max(0, (int) ($item['quantity'] ?? 1)),
            'extra' => is_array($item['extra'] ?? null) ? $item['extra'] : null,
            'position' => $index,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function wireSizes(array $payload): array
    {
        return $this->rows($payload['wire_sizes'] ?? [], fn (array $item, int $index) => [
            'page' => max(0, (int) ($item['page'] ?? 0)),
            'size' => (string) ($item['size'] ?? ''),
            'context' => (string) ($item['context'] ?? ''),
            'count' => max(0, (int) ($item['count'] ?? 1)),
            'position' => $index,
        ], fn (array $row) => filled($row['size']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function circuits(array $payload): array
    {
        return $this->rows($payload['circuits'] ?? [], fn (array $item, int $index) => [
            'page' => max(0, (int) ($item['page'] ?? 0)),
            'number' => (string) ($item['number'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'breaker' => (string) ($item['breaker'] ?? ''),
            'panel' => (string) ($item['panel'] ?? ''),
            'position' => $index,
        ], fn (array $row) => filled($row['number']));
    }

    /**
     * The engine's priced bill of quantities.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function boq(array $payload): array
    {
        return $this->rows($payload['boq'] ?? [], fn (array $item, int $index) => [
            'item' => (string) ($item['item'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'quantity' => round((float) ($item['quantity'] ?? 0), 2),
            'unit' => (string) ($item['unit'] ?? 'ea'),
            'unit_price' => round((float) ($item['unit_price'] ?? 0), 2),
            'subtotal' => round((float) ($item['subtotal'] ?? 0), 2),
            'position' => $index,
        ], fn (array $row) => filled($row['item']));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{subtotal: float, tax_rate: float, tax: float, grand_total: float, currency: string, line_count: int}
     */
    private function estimate(array $payload): array
    {
        $estimate = is_array($payload['estimate'] ?? null) ? $payload['estimate'] : [];

        return [
            'subtotal' => round((float) ($estimate['subtotal'] ?? 0), 2),
            // Reported as a fraction (0.15); percentages are accepted too.
            'tax_rate' => $this->taxRate($estimate['tax_rate'] ?? 0),
            'tax' => round((float) ($estimate['tax'] ?? 0), 2),
            'grand_total' => round((float) ($estimate['grand_total'] ?? 0), 2),
            'currency' => (string) ($estimate['currency'] ?? 'USD'),
            'line_count' => (int) ($estimate['line_count'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function warnings(array $payload): array
    {
        return $this->labels($payload['warnings'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function pipelineStatus(array $payload): array
    {
        $status = $payload['pipeline_status'] ?? [];

        if (! is_array($status)) {
            return [];
        }

        return collect($status)
            ->mapWithKeys(fn ($value, $stage) => [(string) $stage => (string) $value])
            ->all();
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * @param  mixed  $raw
     * @param  callable(array<string, mixed>, int): array<string, mixed>  $map
     * @param  (callable(array<string, mixed>): bool)|null  $keep
     * @return list<array<string, mixed>>
     */
    private function rows($raw, callable $map, ?callable $keep = null): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->values()
            ->filter(fn ($item) => is_array($item))
            ->map($map)
            ->filter(fn (array $row) => $keep === null || $keep($row))
            ->values()
            ->all();
    }

    /**
     * Which detector columns to set. The engine names them template / vector /
     * vision / ocr; anything else is carried in `evidence` instead.
     *
     * @param  mixed  $raw
     * @return array{template: bool, vector: bool, vision: bool, ocr: bool}
     */
    private function sources($raw): array
    {
        $flags = array_fill_keys(array_keys(self::SOURCE_COLUMNS), false);

        foreach ($this->labels($raw) as $label) {
            $key = Str::lower($label);

            if (array_key_exists($key, $flags)) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function labels($raw): array
    {
        if (is_string($raw)) {
            $raw = [$raw];
        }

        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($value) => is_scalar($value) && filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values()
            ->all();
    }

    private function name(mixed $value): string
    {
        return Str::of((string) $value)->squish()->limit(120, '')->value();
    }

    /** Confidence as a 0–1 float; percentages are folded down. */
    private function confidence(mixed $value): float
    {
        $number = (float) $value;

        if ($number > 1) {
            $number /= 100;
        }

        return round(max(0, min(1, $number)), 4);
    }

    /** Tax as a 0–1 fraction, whichever way the engine expressed it. */
    private function taxRate(mixed $value): float
    {
        $number = (float) $value;

        return round($number > 1 ? $number / 100 : $number, 4);
    }

    private function string(mixed $value): ?string
    {
        return blank($value) ? null : trim((string) $value);
    }

    /** @param  list<array<string, mixed>>  $cards */
    private function overallConfidence(array $cards): float
    {
        $counted = collect($cards)->where('origin', SymbolReview::ORIGIN_SYMBOL);

        return round(($counted->isEmpty() ? collect($cards) : $counted)->avg('confidence') ?? 0, 4);
    }
}
