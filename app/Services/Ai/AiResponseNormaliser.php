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
     *     run_id: ?string,
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
        // The current engine (breeze-ai, /api/v1/electrical/extract) answers in a
        // different shape; fold it onto the sections below. The stored payload is
        // never altered — only this read of it.
        if ($this->isElectricalExtraction($payload)) {
            $payload = $this->fromElectricalExtraction($payload);
        }

        $cards = $this->cards($payload);

        if ($cards === []) {
            throw AiApiException::unusablePayload(
                'it contained no symbols and nothing flagged for review.'
            );
        }

        return [
            'project_name' => $this->string($payload['project_name'] ?? null),
            // Carried straight through so crop lookups can ask the engine for this
            // exact run instead of guessing which local debug directory matches it.
            'run_id' => $this->string($payload['run_id'] ?? null),
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

        return $this->uniqueExternalIds($cards);
    }

    /**
     * Two needs-review items can share a description, so a repeated id gets a
     * numeric suffix — an id must identify exactly one card.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    private function uniqueExternalIds(array $cards): array
    {
        $seen = [];

        foreach ($cards as $i => $card) {
            $id = $card['external_id'];
            $seen[$id] = ($seen[$id] ?? 0) + 1;

            if ($seen[$id] > 1) {
                $cards[$i]['external_id'] = $id.'-'.$seen[$id];
            }
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
                'occurrences' => $this->occurrences($item['detections'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * One element per physical detection the engine found for a symbol, so the
     * drawing overlay can draw (and a reviewer can individually approve or
     * reject) every occurrence rather than just the aggregate count.
     *
     * @param  mixed  $raw
     * @return ?list<array<string, mixed>>
     */
    private function occurrences($raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $occurrences = collect($raw)
            ->filter(fn ($item) => is_array($item) && is_array($item['bbox'] ?? null))
            ->map(fn (array $item) => [
                'key' => $this->string($item['id'] ?? null) ?? (string) Str::uuid(),
                'bbox' => [
                    (float) ($item['bbox']['x'] ?? 0),
                    (float) ($item['bbox']['y'] ?? 0),
                    (float) ($item['bbox']['w'] ?? 0),
                    (float) ($item['bbox']['h'] ?? 0),
                ],
                'page' => isset($item['page']) ? (int) $item['page'] : null,
                'confidence' => $this->confidence($item['confidence'] ?? 0),
                // Independent of the engine's own detection status: every
                // occurrence starts approved, same as the card it belongs to.
                'status' => SymbolReview::STATUS_APPROVED,
                'origin' => SymbolReview::OCCURRENCE_ORIGIN_AI,
            ])
            ->values()
            ->all();

        return $occurrences === [] ? null : $occurrences;
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

    /* ------------------------------------------------- breeze-ai extraction */

    /**
     * Normalised page coordinates (0–1) are scaled into this nominal frame so the
     * rest of the pipeline, which works in page pixels, can treat them like any
     * other box. Boxes are drawn as percentages of the frame, so the size itself
     * is arbitrary — only the aspect-free proportion matters.
     */
    public const NORMALISED_FRAME = 10000.0;

    /**
     * Half-size of the box drawn around a point-only location, as a fraction of the
     * page. Roughly a symbol on a 36×24in sheet; height is larger than width so the
     * box looks square on a landscape page.
     */
    private const POINT_BOX_W = 0.012;

    private const POINT_BOX_H = 0.018;

    /**
     * A placement's box in normalised page space: the engine's own `bounding_box`
     * when it sends one, otherwise a small box centred on its `coordinates` point.
     *
     * @return array{x: float, y: float, width: float, height: float}|null
     */
    private function placementBox(mixed $placement): ?array
    {
        if (! is_array($placement)) {
            return null;
        }

        $box = $placement['bounding_box'] ?? null;

        if (is_array($box) && isset($box['x'], $box['y'])) {
            return [
                'x' => (float) $box['x'],
                'y' => (float) $box['y'],
                'width' => (float) ($box['width'] ?? self::POINT_BOX_W),
                'height' => (float) ($box['height'] ?? self::POINT_BOX_H),
            ];
        }

        $point = $placement['coordinates'] ?? null;

        if (! is_array($point) || ! is_numeric($point['x'] ?? null) || ! is_numeric($point['y'] ?? null)) {
            return null;
        }

        return [
            'x' => max(0.0, (float) $point['x'] - self::POINT_BOX_W / 2),
            'y' => max(0.0, (float) $point['y'] - self::POINT_BOX_H / 2),
            'width' => self::POINT_BOX_W,
            'height' => self::POINT_BOX_H,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public function isElectricalExtraction(array $payload): bool
    {
        return array_key_exists('components', $payload) && array_key_exists('document', $payload);
    }

    /**
     * Per-page nominal sizes for an extraction payload, for `ai_results.page_sizes`.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{width: float, height: float}>
     */
    public function nominalPageSizes(array $payload): array
    {
        $pages = max(0, (int) ($payload['document']['total_pages'] ?? 0));
        $sizes = [];

        for ($page = 1; $page <= $pages; $page++) {
            $sizes[$page] = ['width' => self::NORMALISED_FRAME, 'height' => self::NORMALISED_FRAME];
        }

        return $sizes;
    }

    /**
     * Rebuilds the sections `normalise()` reads from a breeze-ai
     * `ExtractionResponse`: components → symbols (merged by name, one card per
     * type), unidentified_symbols → needs_review, boards/circuits → panel
     * schedules and circuits, cables_and_wires → wire sizes, bill_of_quantities
     * → boq.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fromElectricalExtraction(array $payload): array
    {
        $frame = self::NORMALISED_FRAME;
        $symbols = [];
        $known = [];
        $unknown = [];
        $rejected = [];

        foreach ((array) ($payload['components'] ?? []) as $component) {
            if (! is_array($component) || blank($component['name'] ?? null)) {
                continue;
            }

            $name = $this->name($component['name']);
            $key = Str::lower($name);
            $quantity = $component['quantity']['value'] ?? null;

            $symbols[$key] ??= [
                'name' => $name,
                'count' => 0,
                'confidence' => [],
                'sources' => [],
                'evidence' => [],
                'detections' => [],
            ];

            $symbols[$key]['count'] += is_numeric($quantity) ? (int) round((float) $quantity) : 0;

            if (isset($component['confidence'])) {
                $symbols[$key]['confidence'][] = (float) $component['confidence'];
            }

            $symbols[$key]['sources'] = [...$symbols[$key]['sources'], ...array_filter([$component['quantity']['method'] ?? null])];
            $symbols[$key]['evidence'] = [...$symbols[$key]['evidence'], ...array_filter([
                $component['category'] ?? null,
                $component['type'] ?? null,
                $component['symbol'] ?? null,
            ])];

            foreach ((array) ($component['placement'] ?? []) as $placement) {
                $box = $this->placementBox($placement);

                if ($box === null) {
                    continue;
                }

                $symbols[$key]['detections'][] = [
                    'id' => ($component['component_id'] ?? 'c').'-'.count($symbols[$key]['detections']),
                    'bbox' => [
                        'x' => (float) ($box['x'] ?? 0) * $frame,
                        'y' => (float) ($box['y'] ?? 0) * $frame,
                        'w' => (float) ($box['width'] ?? 0) * $frame,
                        'h' => (float) ($box['height'] ?? 0) * $frame,
                    ],
                    'page' => $placement['page'] ?? null,
                    'confidence' => $placement['position_confidence'] ?? $component['confidence'] ?? 0,
                ];
            }

            match ($component['review_status'] ?? 'pending') {
                'rejected' => $rejected[$key] = ['name' => $name],
                'needs_review' => $unknown[$key] = ['name' => $name],
                default => $known[$key] = ['name' => $name],
            };
        }

        $summaries = array_values(array_map(fn (array $row) => [
            ...$row,
            'confidence' => $row['confidence'] === [] ? 0 : array_sum($row['confidence']) / count($row['confidence']),
            'sources' => array_values(array_unique($row['sources'])),
            'evidence' => array_values(array_unique($row['evidence'])),
        ], $symbols));

        $needsReview = [];

        foreach ((array) ($payload['unidentified_symbols'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $needsReview[] = [
                'name' => $item['description'] ?? 'Unidentified symbol',
                'count' => 1,
                'confidence' => $item['confidence'] ?? 0,
                'source' => 'vision',
                'reason' => $item['reason'] ?? null,
                'page' => $item['page'] ?? null,
            ];
        }

        $circuits = [];
        $byBoard = [];

        foreach ((array) ($payload['circuits'] ?? []) as $circuit) {
            if (! is_array($circuit)) {
                continue;
            }

            $row = [
                'number' => $circuit['circuit_number'] ?? '',
                'description' => $circuit['description'] ?? '',
                'breaker' => $circuit['breaker'] ?? '',
                'panel' => $circuit['board_name'] ?? '',
                'page' => $circuit['page'] ?? 0,
            ];

            $circuits[] = $row;
            $byBoard[Str::lower((string) $row['panel'])][] = [
                'circuit' => $row['number'],
                'description' => $row['description'],
                'breaker' => $row['breaker'],
                'wire' => $circuit['wire'] ?? '',
            ];
        }

        $panelSchedules = [];
        $equipment = [];

        foreach ((array) ($payload['boards'] ?? []) as $board) {
            if (! is_array($board)) {
                continue;
            }

            $panelSchedules[] = [
                'page' => $board['page'] ?? 0,
                'panel_name' => $board['name'] ?? '',
                'rows' => $byBoard[Str::lower((string) ($board['name'] ?? ''))] ?? [],
                'raw_headers' => ['circuit', 'description', 'breaker', 'wire'],
            ];

            $equipment[] = [
                'page' => $board['page'] ?? 0,
                'tag' => $board['name'] ?? '',
                'description' => $board['kind'] ?? 'panelboard',
                'rating' => '',
                'quantity' => 1,
            ];
        }

        $wireSizes = [];

        foreach ((array) ($payload['cables_and_wires'] ?? []) as $cable) {
            if (! is_array($cable)) {
                continue;
            }

            $length = $cable['length']['value'] ?? null;

            $wireSizes[] = [
                'page' => $cable['source']['page'] ?? 0,
                'size' => $cable['type'] ?? $cable['name'] ?? '',
                'context' => trim(($cable['name'] ?? '').(is_numeric($length)
                    ? ' — '.round((float) $length, 2).' '.($cable['length']['unit'] ?? '')
                    : '')),
                'count' => 1,
            ];
        }

        $boq = [];
        $boqTotal = 0.0;

        foreach ((array) ($payload['bill_of_quantities'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $qty = (float) ($item['quantity']['value'] ?? 0);
            $rate = (float) ($item['unit_rate'] ?? 0);
            $boqTotal += $qty * $rate;

            $boq[] = [
                'item' => $item['description'] ?? $item['item_id'] ?? '',
                'description' => $item['category'] ?? '',
                'quantity' => $qty,
                'unit' => $item['quantity']['unit'] ?? $item['rate_unit'] ?? 'ea',
                'unit_price' => $rate,
                'subtotal' => $qty * $rate,
            ];
        }

        $total = $payload['estimate']['total'] ?? null;
        $total = is_numeric($total) ? (float) $total : $boqTotal;

        $meta = (array) ($payload['processing_metadata'] ?? []);

        return [
            'project_name' => $payload['building']['name'] ?? $payload['document']['filename'] ?? null,
            'run_id' => null,
            'pages' => $payload['document']['total_pages'] ?? 0,
            'symbol_counts' => collect($summaries)->mapWithKeys(fn (array $row) => [$row['name'] => $row['count']])->all(),
            'symbols' => $summaries,
            'known_symbols' => array_values($known),
            'unknown_symbols' => array_values($unknown),
            'rejected_symbols' => array_values($rejected),
            'needs_review' => $needsReview,
            'panel_schedules' => $panelSchedules,
            'equipment' => $equipment,
            'wire_sizes' => $wireSizes,
            'circuits' => $circuits,
            'boq' => $boq,
            'estimate' => [
                'subtotal' => $total,
                'tax_rate' => 0,
                'tax' => 0,
                'grand_total' => $total,
                'currency' => $payload['estimate']['currency'] ?? 'USD',
                'line_count' => count($boq),
            ],
            'warnings' => $payload['assumptions_and_limitations'] ?? [],
            'processing_time' => 0,
            'pipeline_status' => array_filter([
                'legend' => ($meta['legend_found'] ?? false) ? 'found' : 'not_found',
                'rag' => $meta['rag_status'] ?? null,
                'model' => $meta['model'] ?? null,
            ]),
        ];
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
