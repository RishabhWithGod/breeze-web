<?php

namespace App\Services\Takeoff;

use App\Events\EstimateGenerated;
use App\Models\AiResult;
use App\Models\BoqLine;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\FinalSymbol;
use App\Models\Job;
use App\Models\SymbolReview;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates a priced estimate from a signed-off takeoff.
 *
 * Prices come from the engine: its `boq` lines become the estimate's line items and
 * its `estimate.tax_rate` becomes the tax applied. Quantities come from the
 * *review* — where a BOQ line was matched to a reviewed symbol the reviewed count
 * wins, so overturning the AI actually changes the money.
 *
 * Everything written here is an editable starting point: lines, rates, markup and
 * tax can all be changed afterwards, and the totals are recomputed from the lines.
 */
class EstimateBuilder
{
    /** Keywords that place an engine BOQ line in a section. */
    private const CATEGORY_HINTS = [
        EstimateItem::CATEGORY_LABOR => ['labor', 'labour', 'install', 'hour'],
        EstimateItem::CATEGORY_EQUIPMENT => ['panel', 'switchboard', 'transformer', 'gear', 'disconnect'],
        EstimateItem::CATEGORY_FIXTURE => ['fixture', 'luminaire', 'light', 'lamp', 'pendant', 'exit sign'],
    ];

    public function __construct(private readonly SymbolCatalog $catalog) {}

    /**
     * Prices the drawing straight off the engine's response, before review.
     *
     * The estimator gets numbers to work with the moment the drawing is read;
     * signing off the review then rewrites these AI lines at the reviewed counts.
     * Quantities here are the engine's own, and the notes say so.
     */
    public function fromEngineResponse(AiResult $result, User $user, ?Job $job = null): Estimate
    {
        $engineLines = $result->boqLines()->get();
        $counted = $result->reviews()->where('status', SymbolReview::STATUS_APPROVED)->get();

        if ($engineLines->isEmpty() && $counted->isEmpty()) {
            throw new RuntimeException('Nothing on this drawing could be priced automatically.');
        }

        $job ??= $result->workJob;

        return DB::transaction(function () use ($result, $engineLines, $counted, $job, $user) {
            if ($result->estimate) {
                return $result->estimate;
            }

            $estimate = $this->open($result, $job, $engineLines, reviewed: false);

            $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, collect())
                : $this->writeReviewLines($estimate, $counted);

            $estimate->recalculateTotals();
            $estimate->refresh();

            $result->update(['estimate_id' => $estimate->id]);
            $result->setRelation('estimate', $estimate);

            $job?->recordActivity(
                'estimate_created',
                "Estimate {$estimate->number} generated from the AI response, pending review",
                ['estimate_id' => $estimate->id, 'number' => $estimate->number],
            );

            $result->recordHistory(
                'estimate_created',
                "Estimate {$estimate->number} generated from the AI response",
                to: '$'.number_format((float) $estimate->grand_total, 2),
                meta: [
                    'estimate_id' => $estimate->id,
                    'user_id' => $user->id,
                    'reviewed' => false,
                ],
            );

            EstimateGenerated::dispatch($estimate);

            return $estimate;
        });
    }

    public function fromFinalJson(AiResult $result, User $user, ?Job $job = null): Estimate
    {
        if (blank($result->final_payload)) {
            throw new RuntimeException('Please finish and sign off the review before pricing this takeoff.');
        }

        $job ??= $result->workJob;
        $symbols = $result->finalSymbols()->where('count', '>', 0)->get();

        if ($symbols->isEmpty()) {
            throw new RuntimeException('Nothing was approved in the review, so there is nothing to price yet.');
        }

        // An estimate raised at analysis time is brought up to the reviewed counts
        // rather than duplicated.
        if ($result->estimate) {
            return $this->reprice($result->estimate, $result, $symbols, $user, $job);
        }

        return DB::transaction(function () use ($result, $symbols, $job, $user) {
            $engineEstimate = $result->ai_estimate ?? [];
            $engineLines = $result->boqLines()->get();

            $estimate = $this->open($result, $job, $engineLines, reviewed: true);

            $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, $symbols)
                : $this->writeCatalogLines($estimate, $symbols);

            $estimate->recalculateTotals();
            $estimate->refresh();

            $result->update(['estimate_id' => $estimate->id]);
            $result->setRelation('estimate', $estimate);

            $job?->recordActivity(
                'estimate_created',
                "Estimate {$estimate->number} generated from the reviewed takeoff",
                ['estimate_id' => $estimate->id, 'number' => $estimate->number],
            );

            $result->recordHistory(
                'estimate_created',
                "Estimate {$estimate->number} generated from the final JSON",
                to: '$'.number_format((float) $estimate->grand_total, 2),
                meta: [
                    'estimate_id' => $estimate->id,
                    'user_id' => $user->id,
                    'source' => $engineLines->isNotEmpty() ? 'engine-boq' : 'price-book',
                    'engine_grand_total' => Arr::get($engineEstimate, 'grand_total'),
                ],
            );

            EstimateGenerated::dispatch($estimate);

            return $estimate;
        });
    }

    /**
     * The estimate header, identical at both stages so signing off changes numbers
     * rather than the record.
     *
     * @param  Collection<int, BoqLine>  $engineLines
     */
    private function open(AiResult $result, ?Job $job, Collection $engineLines, bool $reviewed): Estimate
    {
        $project = $result->project;
        $engineEstimate = $result->ai_estimate ?? [];

        return Estimate::create([
            'job_id' => $job?->id,
            'project_id' => $project->id,
            'ai_result_id' => $result->id,
            'number' => Estimate::nextNumber(),
            'client' => $job?->client ?? ($project->client === 'Unassigned' ? 'Unassigned' : $project->client),
            'project' => $job?->name ?? $project->name,
            'issued_on' => now()->toDateString(),
            'status' => 'draft',
            'markup_pct' => (float) config('ai.estimating.markup_pct'),
            // The engine reports tax as a fraction; config fills the gap.
            'tax_pct' => $this->taxPercent($engineEstimate),
            'notes' => $this->notes($engineLines, $engineEstimate, $reviewed),
            'amount' => 0,
        ]);
    }

    /**
     * Rewrites the AI lines of an existing estimate at the reviewed counts.
     *
     * Only lines the AI put there are replaced. Anything the estimator added or
     * priced by hand survives — their work is not the takeoff's to discard. Rates
     * they edited on an AI line do not survive, because the line's quantity and
     * identity come from the drawing; the audit row records the movement.
     *
     * @param  Collection<int, FinalSymbol>  $symbols
     */
    private function reprice(Estimate $estimate, AiResult $result, Collection $symbols, User $user, ?Job $job = null): Estimate
    {
        return DB::transaction(function () use ($estimate, $result, $symbols, $user, $job) {
            $before = (float) $estimate->grand_total;
            $manual = $estimate->items()->where('source', 'manual')->count();
            $engineLines = $result->boqLines()->get();

            $estimate->items()->where('source', 'ai')->delete();

            $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, $symbols)
                : $this->writeCatalogLines($estimate, $symbols);

            // Manual lines keep their own positions after the rewritten AI block.
            $estimate->update([
                'notes' => $this->notes($engineLines, $result->ai_estimate ?? [], reviewed: true),
                // The estimate may have been raised (e.g. at finalise) before a
                // job existed yet — link it the first time one shows up, without
                // overwriting a link that's already there.
                ...($job && ! $estimate->job_id ? [
                    'job_id' => $job->id,
                    'client' => $job->client ?? $estimate->client,
                    'project' => $job->name ?? $estimate->project,
                ] : []),
            ]);
            $estimate->recalculateTotals();
            $estimate->refresh();

            $estimate->job?->recordActivity(
                'estimate_updated',
                "Estimate {$estimate->number} repriced from the reviewed takeoff",
                ['estimate_id' => $estimate->id, 'number' => $estimate->number],
            );

            $result->recordHistory(
                'estimate_updated',
                "Estimate {$estimate->number} repriced from the final JSON",
                from: '$'.number_format($before, 2),
                to: '$'.number_format((float) $estimate->grand_total, 2),
                meta: [
                    'estimate_id' => $estimate->id,
                    'user_id' => $user->id,
                    'manual_lines_kept' => $manual,
                    'reviewed' => true,
                ],
            );

            return $estimate;
        });
    }

    /**
     * Price-book lines from the engine's own counts, for a drawing it did not price.
     *
     * @param  Collection<int, SymbolReview>  $reviews
     */
    private function writeReviewLines(Estimate $estimate, Collection $reviews): void
    {
        $laborRate = (float) config('ai.estimating.labor_rate');
        $position = 0;

        foreach ($reviews as $review) {
            $count = (int) $review->final_count;

            // Nothing to price or install for a symbol reviewed down to zero.
            if ($count <= 0) {
                continue;
            }

            $rates = $this->catalog->for($review->name);

            $estimate->items()->create([
                'category' => $rates['category'],
                'description' => Str::of($review->name)->headline()->value(),
                'unit' => $rates['unit'],
                'quantity' => $count,
                'unit_cost' => $rates['unit_cost'],
                'source' => 'ai',
                'position' => $position++,
            ]);

            if ($rates['labor_hours'] > 0) {
                $estimate->items()->create([
                    'category' => EstimateItem::CATEGORY_LABOR,
                    'description' => 'Install labor — '.Str::of($review->name)->headline()->value(),
                    'unit' => 'hr',
                    'quantity' => round($rates['labor_hours'] * $count, 2),
                    'unit_cost' => $laborRate,
                    'source' => 'ai',
                    'position' => $position++,
                ]);
            }
        }
    }

    /**
     * One estimate line per engine BOQ line, at the reviewed quantity.
     *
     * @param  Collection<int, BoqLine>  $lines
     * @param  Collection<int, FinalSymbol>  $symbols
     */
    private function writeEngineLines(Estimate $estimate, Collection $lines, Collection $symbols): void
    {
        $symbolsById = $symbols->keyBy('id');
        $position = 0;

        foreach ($lines as $line) {
            $symbol = $line->final_symbol_id ? $symbolsById->get($line->final_symbol_id) : null;

            $estimate->items()->create([
                'final_symbol_id' => $symbol?->id,
                'category' => $this->categoryFor($line->item),
                'description' => $line->description === ''
                    ? $line->item
                    : "{$line->item} — {$line->description}",
                'unit' => $line->unit ?: 'ea',
                // Reviewed count where the line maps to a symbol, else the engine's
                // own quantity.
                'quantity' => $symbol ? $symbol->count : (float) $line->quantity,
                'unit_cost' => (float) $line->unit_price,
                'source' => 'ai',
                'position' => $position++,
            ]);
        }
    }

    /**
     * Fallback when the engine priced nothing: the configured price book.
     *
     * Only reached for a response whose `boq` was empty, and the estimate's notes
     * say so.
     *
     * @param  Collection<int, FinalSymbol>  $symbols
     */
    private function writeCatalogLines(Estimate $estimate, Collection $symbols): void
    {
        $laborRate = (float) config('ai.estimating.labor_rate');
        $position = 0;

        foreach ($symbols as $symbol) {
            $rates = $this->catalog->for($symbol->name);
            $count = (int) $symbol->count;

            $estimate->items()->create([
                'final_symbol_id' => $symbol->id,
                'category' => $rates['category'],
                'description' => Str::of($symbol->name)->headline()->value(),
                'unit' => $rates['unit'],
                'quantity' => $count,
                'unit_cost' => $rates['unit_cost'],
                'source' => 'ai',
                'position' => $position++,
            ]);

            if ($rates['labor_hours'] > 0) {
                $estimate->items()->create([
                    'final_symbol_id' => $symbol->id,
                    'category' => EstimateItem::CATEGORY_LABOR,
                    'description' => 'Install labor — '.Str::of($symbol->name)->headline()->value(),
                    'unit' => 'hr',
                    'quantity' => round($rates['labor_hours'] * $count, 2),
                    'unit_cost' => $laborRate,
                    'source' => 'ai',
                    'position' => $position++,
                ]);
            }
        }
    }

    private function categoryFor(string $item): string
    {
        $needle = Str::lower($item);

        foreach (self::CATEGORY_HINTS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $category;
                }
            }
        }

        return EstimateItem::CATEGORY_MATERIAL;
    }

    /** @param  array<string, mixed>  $engineEstimate */
    private function taxPercent(array $engineEstimate): float
    {
        $rate = (float) ($engineEstimate['tax_rate'] ?? 0);

        if ($rate <= 0) {
            return (float) config('ai.estimating.tax_pct');
        }

        // Stored as a fraction by the engine (0.15 → 15%).
        return round($rate <= 1 ? $rate * 100 : $rate, 2);
    }

    /**
     * @param  Collection<int, BoqLine>  $lines
     * @param  array<string, mixed>  $engineEstimate
     */
    private function notes(Collection $lines, array $engineEstimate, bool $reviewed = true): string
    {
        $stage = $reviewed
            ? 'Quantities follow the reviewed counts'
            : 'Quantities are the engine\'s own and are rewritten when the review is signed off';

        if ($lines->isEmpty()) {
            return 'The AI engine returned no priced bill of quantities for this drawing, '
                ."so these lines come from the configured price book. {$stage}. Review every rate.";
        }

        $currency = (string) ($engineEstimate['currency'] ?? 'USD');
        $total = number_format((float) ($engineEstimate['grand_total'] ?? 0), 2);

        return "Generated from the AI engine's bill of quantities ({$lines->count()} lines, "
            ."{$currency} {$total} before review). {$stage}; "
            .'every line, rate and percentage remains editable.';
    }

    /** Line-item summary used by the estimate screen and its PDF. */
    public static function summarise(Estimate $estimate): array
    {
        $items = $estimate->items()->get();

        return collect(EstimateItem::CATEGORIES)
            ->mapWithKeys(fn (string $category) => [
                $category => [
                    'label' => EstimateItem::CATEGORY_LABELS[$category],
                    'lines' => $items->where('category', $category)->count(),
                    'total' => round((float) $items->where('category', $category)->sum('total'), 2),
                ],
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public static function totalsFor(Estimate $estimate): array
    {
        $engine = $estimate->aiResult?->ai_estimate ?? [];

        return [
            'material' => (float) $estimate->material_total,
            'labor' => (float) $estimate->labor_total,
            'equipment' => (float) $estimate->equipment_total,
            'subtotal' => (float) $estimate->subtotal,
            'markupPct' => (float) $estimate->markup_pct,
            'markup' => (float) $estimate->markup_total,
            'taxPct' => (float) $estimate->tax_pct,
            'tax' => (float) $estimate->tax_total,
            'grandTotal' => (float) $estimate->grand_total,
            'laborHours' => round(
                (float) $estimate->items()->where('category', EstimateItem::CATEGORY_LABOR)->sum('quantity'),
                2,
            ),
            // What the engine priced before review, for comparison.
            'engineSubtotal' => (float) ($engine['subtotal'] ?? 0),
            'engineGrandTotal' => (float) ($engine['grand_total'] ?? 0),
            'engineLineCount' => (int) ($engine['line_count'] ?? 0),
            'currency' => (string) ($engine['currency'] ?? 'USD'),
        ];
    }
}
