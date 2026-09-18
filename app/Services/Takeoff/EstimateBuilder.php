<?php

namespace App\Services\Takeoff;

use App\Events\EstimateGenerated;
use App\Models\AiResult;
use App\Models\BoqLine;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\FinalSymbol;
use App\Models\Job;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Models\User;
use App\Services\Estimating\ProjectRateBook;
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

    public function __construct(
        private readonly SymbolCatalog $catalog,
        private readonly ProjectRateBook $rateBook,
        private readonly PriceBookLookup $priceBook,
    ) {}

    /**
     * One estimate line for a device, priced at whatever the catalog resolved.
     *
     * Written in one place because all three paths into this class need the
     * same three things and used to disagree about them: the fuller name the
     * rate list knows the item by, the rate's provenance so a guess can be
     * marked as one, and a labour line at hours the company actually books.
     *
     * @param  array<string, mixed>  $rates
     * @param  list<int>  $matchedRateItemIds  Appended to when `$rates` came
     *     from this project's own rate list, so the caller can find what's
     *     left over once every device has been written.
     */
    private function writeDevice(
        Estimate $estimate,
        array $rates,
        string $fallbackName,
        float $quantity,
        int &$position,
        ProjectRateBook $rateBook,
        PriceBookLookup $priceBook,
        ?float $clientLaborRate,
        array &$matchedRateItemIds,
        ?int $finalSymbolId = null,
    ): void {
        /*
         * The workbook's wording wins over the drawing's. A lighting plan has
         * room for "EM2"; the schedule behind it says "EM2, NEW BATTERY 2/HEAD
         * EM FIXTURE", and that is what belongs on a document a client reads.
         */
        $description = $rates['description'] ?? Str::of($fallbackName)->headline()->value();

        $estimate->items()->create([
            'final_symbol_id' => $finalSymbolId,
            'category' => $rates['category'],
            'description' => $description,
            'unit' => $rates['unit'],
            'quantity' => $quantity,
            'unit_cost' => $rates['unit_cost'],
            'source' => 'ai',
            'pricing_source' => $rates['source'],
            'price_book_item_id' => $rates['price_book_item_id'] ?? null,
            'project_rate_item_id' => $rates['project_rate_item_id'] ?? null,
            'pricing_confidence' => $rates['confidence'],
            'position' => $position++,
        ]);

        if ($rates['source'] === 'vendor-rate-list' && $rates['project_rate_item_id']) {
            $matchedRateItemIds[] = $rates['project_rate_item_id'];
        }

        if ($rates['labor_hours'] <= 0) {
            return;
        }

        $estimate->items()->create([
            'final_symbol_id' => $finalSymbolId,
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => 'Install labor — '.$description,
            'unit' => 'hr',
            'quantity' => round($rates['labor_hours'] * $quantity, 4),
            'unit_cost' => $this->effectiveLaborRate($rateBook, $priceBook, $clientLaborRate),
            'source' => 'ai',
            // The hours are the same book's rates came from, so the line is
            // labelled by where the hours came from.
            'pricing_source' => $rates['source'],
            'price_book_item_id' => $rates['price_book_item_id'] ?? null,
            'project_rate_item_id' => $rates['project_rate_item_id'] ?? null,
            'pricing_confidence' => $rates['confidence'],
            'position' => $position++,
        ]);
    }

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

            $project = $result->project;
            $catalog = $this->catalog->forProject($project->id, $project->user_id);
            $rateBook = $this->rateBook->forProject($project->id);
            $priceBook = $this->priceBook->forUser($project->user_id);
            $clientLaborRate = $this->clientLaborRate($project);

            $estimate = $this->open($result, $job, $engineLines, reviewed: false, rateBook: $rateBook, priceBook: $priceBook);

            $matchedRateItemIds = $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, collect(), $catalog, $rateBook, $priceBook, $clientLaborRate)
                : $this->writeReviewLines($estimate, $counted, $catalog, $rateBook, $priceBook, $clientLaborRate);

            $this->writeUnmatchedRateBookLines($estimate, $rateBook, $matchedRateItemIds);

            $estimate->recalculateTotals();
            $this->scaleToTarget($estimate, $this->projectTarget($result));
            $estimate->refresh();
            $this->syncJobBudget($estimate);

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

            $project = $result->project;
            $catalog = $this->catalog->forProject($project->id, $project->user_id);
            $rateBook = $this->rateBook->forProject($project->id);
            $priceBook = $this->priceBook->forUser($project->user_id);
            $clientLaborRate = $this->clientLaborRate($project);

            $estimate = $this->open($result, $job, $engineLines, reviewed: true, rateBook: $rateBook, priceBook: $priceBook);

            $matchedRateItemIds = $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, $symbols, $catalog, $rateBook, $priceBook, $clientLaborRate)
                : $this->writeCatalogLines($estimate, $symbols, $catalog, $rateBook, $priceBook, $clientLaborRate);

            $this->writeUnmatchedRateBookLines($estimate, $rateBook, $matchedRateItemIds);

            $estimate->recalculateTotals();
            $this->scaleToTarget($estimate, $this->projectTarget($result));
            $estimate->refresh();
            $this->syncJobBudget($estimate);

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
                    'source' => $engineLines->isNotEmpty() ? 'engine-boq' : 'vendor-rate-list',
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
    private function open(AiResult $result, ?Job $job, Collection $engineLines, bool $reviewed, ProjectRateBook $rateBook, PriceBookLookup $priceBook): Estimate
    {
        $project = $result->project;
        $engineEstimate = $result->ai_estimate ?? [];
        // Set when this run came from "Upload Addendum" for an existing
        // estimate, rather than a fresh, standalone takeoff — see
        // `Upload::addendum_for_estimate_id`.
        $parentEstimateId = $result->addendum_for_estimate_id;

        return Estimate::create([
            'job_id' => $job?->id,
            'project_id' => $project->id,
            'ai_result_id' => $result->id,
            // The estimate's own owner, not necessarily whoever is signed in
            // when this runs — this can fire from a queued takeoff with no
            // acting user at all, so the project's manager is authoritative.
            'number' => Estimate::nextNumber($project->user),
            'client' => $job?->client ?? ($project->client === 'Unassigned' ? 'Unassigned' : $project->client),
            'project' => $job?->name ?? $project->name,
            'issued_on' => now()->toDateString(),
            // Draft only while no job has been raised against it yet.
            'status' => Estimate::statusFor($job),
            'kind' => $parentEstimateId ? Estimate::KIND_ADDENDUM : Estimate::KIND_STANDALONE,
            'parent_estimate_id' => $parentEstimateId,
            'addendum_number' => $parentEstimateId ? Estimate::nextAddendumNumber($parentEstimateId) : null,
            /*
             * The rates these jobs are actually bid at, read off this
             * project's own imported workbook first — overheads and profit
             * together, because the estimate carries one markup line and the
             * bids carry two. Falls back to the price book while nothing has
             * been imported for this project, then to config.
             */
            'markup_pct' => $this->markupPercent($rateBook, $priceBook),
            'tax_pct' => $this->taxPercent($engineEstimate, $rateBook, $priceBook),
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

            $project = $result->project;
            $catalog = $this->catalog->forProject($project->id, $project->user_id);
            $rateBook = $this->rateBook->forProject($project->id);
            $priceBook = $this->priceBook->forUser($project->user_id);
            $clientLaborRate = $this->clientLaborRate($project);

            $estimate->items()->where('source', 'ai')->delete();

            $matchedRateItemIds = $engineLines->isNotEmpty()
                ? $this->writeEngineLines($estimate, $engineLines, $symbols, $catalog, $rateBook, $priceBook, $clientLaborRate)
                : $this->writeCatalogLines($estimate, $symbols, $catalog, $rateBook, $priceBook, $clientLaborRate);

            $this->writeUnmatchedRateBookLines($estimate, $rateBook, $matchedRateItemIds);

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
                    // The job it was waiting for has arrived: no longer a draft.
                    ...($estimate->status === 'draft'
                        ? ['status' => Estimate::STATUS_FOR_A_LIVE_JOB]
                        : []),
                ] : []),
            ]);
            $estimate->recalculateTotals();
            $this->scaleToTarget($estimate, $this->projectTarget($result));
            $estimate->refresh();
            $this->syncJobBudget($estimate);

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
     * Lines from the engine's own counts, for a drawing it did not price.
     *
     * @param  Collection<int, SymbolReview>  $reviews
     * @return list<int>  The rate list items matched along the way.
     */
    private function writeReviewLines(Estimate $estimate, Collection $reviews, SymbolCatalog $catalog, ProjectRateBook $rateBook, PriceBookLookup $priceBook, ?float $clientLaborRate): array
    {
        $position = 0;
        $matchedRateItemIds = [];

        foreach ($reviews as $review) {
            $count = (int) $review->final_count;

            // Nothing to price or install for a symbol reviewed down to zero.
            if ($count <= 0) {
                continue;
            }

            $this->writeDevice(
                $estimate,
                $catalog->for($review->name),
                $review->name,
                $count,
                $position,
                $rateBook,
                $priceBook,
                $clientLaborRate,
                $matchedRateItemIds,
            );
        }

        return $matchedRateItemIds;
    }

    /**
     * One estimate line per engine BOQ line, at the reviewed quantity.
     *
     * @param  Collection<int, BoqLine>  $lines
     * @param  Collection<int, FinalSymbol>  $symbols
     * @return list<int>  The rate list items matched along the way.
     */
    private function writeEngineLines(Estimate $estimate, Collection $lines, Collection $symbols, SymbolCatalog $catalog, ProjectRateBook $rateBook, PriceBookLookup $priceBook, ?float $clientLaborRate): array
    {
        $symbolsById = $symbols->keyBy('id');
        $position = 0;
        $matchedRateItemIds = [];

        foreach ($lines as $line) {
            $symbol = $line->final_symbol_id ? $symbolsById->get($line->final_symbol_id) : null;
            // Reviewed count where the line maps to a symbol, else the engine's
            // own quantity.
            $quantity = $symbol ? $symbol->count : (float) $line->quantity;
            $name = $symbol?->name ?? $line->item;

            $rates = $catalog->for($name);
            $lineUnit = Str::lower(trim($line->unit ?: 'ea'));

            $this->writeEngineLine(
                $estimate,
                $line,
                $symbol,
                $quantity,
                $rates,
                $position,
            );

            if ($rates['source'] === 'vendor-rate-list' && $rates['project_rate_item_id']) {
                $matchedRateItemIds[] = $rates['project_rate_item_id'];
            }

            /*
             * Labour, in the hours an estimator actually books. Only when the
             * rate list's unit is the line's unit: its rate for conduit is
             * hours per *foot*, and charging that per device — or a per-device
             * rate across a 250 ft run — is wrong by two orders of magnitude in
             * whichever direction the mismatch happens to fall. Nothing to book
             * at all for a line this project's rate list has never seen.
             */
            $unitsAgree = $rates['matched'] && Str::lower($rates['unit']) === $lineUnit;

            $hours = $unitsAgree ? round($rates['labor_hours'] * $quantity, 4) : 0.0;

            if ($hours <= 0) {
                continue;
            }

            $estimate->items()->create([
                'final_symbol_id' => $symbol?->id,
                'category' => EstimateItem::CATEGORY_LABOR,
                'description' => 'Install labor — '.($rates['description'] ?? Str::of($line->item)->headline()->value()),
                'unit' => 'hr',
                'quantity' => $hours,
                'unit_cost' => $this->effectiveLaborRate($rateBook, $priceBook, $clientLaborRate),
                'source' => 'ai',
                'pricing_source' => $rates['source'],
                'price_book_item_id' => $rates['price_book_item_id'] ?? null,
                'project_rate_item_id' => $rates['project_rate_item_id'] ?? null,
                'pricing_confidence' => $rates['confidence'],
                'position' => $position++,
            ]);
        }

        return $matchedRateItemIds;
    }

    /**
     * The material line for one engine BOQ row.
     *
     * The price is only ever this project's own uploaded rate list, or the
     * price book once that has nothing to say. The engine's own `unit_price`
     * on the BOQ line is never used — it is a guess the drawing's analysis
     * made up, not a rate anyone has actually charged, and the whole point of
     * matching against a real rate list is that a line either carries a real
     * number or carries none at all for the estimator to fill in.
     *
     * @param  array<string, mixed>  $rates  from {@see SymbolCatalog::for()} — matched or not, always present
     */
    private function writeEngineLine(
        Estimate $estimate,
        BoqLine $line,
        ?FinalSymbol $symbol,
        float $quantity,
        array $rates,
        int &$position,
    ): void {
        $engineDescription = $line->description === ''
            ? $line->item
            : "{$line->item} — {$line->description}";

        $estimate->items()->create([
            'final_symbol_id' => $symbol?->id,
            'category' => $rates['matched'] ? $rates['category'] : $this->categoryFor($line->item),
            // The workbook's own wording where there is one: the drawing had
            // room for a tag, the schedule has the thing itself.
            'description' => $rates['description'] ?? $engineDescription,
            // The engine's own unit where nothing matched — it read the
            // drawing correctly even when the rate list has nothing to say
            // about the rate, and "FT" should not silently become "EA".
            'unit' => $rates['matched'] ? $rates['unit'] : ($line->unit ?: 'ea'),
            'quantity' => $quantity,
            'unit_cost' => $rates['unit_cost'],
            'source' => 'ai',
            'pricing_source' => $rates['source'],
            'price_book_item_id' => $rates['price_book_item_id'] ?? null,
            'project_rate_item_id' => $rates['project_rate_item_id'] ?? null,
            'pricing_confidence' => $rates['confidence'],
            'position' => $position++,
        ]);
    }

    /**
     * Fallback when the engine priced nothing: this project's own vendor rate
     * list, then the price book.
     *
     * Only reached for a response whose `boq` was empty, and the estimate's notes
     * say so.
     *
     * @param  Collection<int, FinalSymbol>  $symbols
     * @return list<int>  The rate list items matched along the way.
     */
    private function writeCatalogLines(Estimate $estimate, Collection $symbols, SymbolCatalog $catalog, ProjectRateBook $rateBook, PriceBookLookup $priceBook, ?float $clientLaborRate): array
    {
        $position = 0;
        $matchedRateItemIds = [];

        foreach ($symbols as $symbol) {
            $this->writeDevice(
                $estimate,
                $catalog->for($symbol->name),
                $symbol->name,
                (int) $symbol->count,
                $position,
                $rateBook,
                $priceBook,
                $clientLaborRate,
                $matchedRateItemIds,
                $symbol->id,
            );
        }

        return $matchedRateItemIds;
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

    /**
     * The tax an estimate is raised at.
     *
     * The engine's rate first, because it read the drawing's own jurisdiction;
     * then the rate this project's own workbook was actually bid at; then the
     * price book's; then config. The imported bid is the important step:
     * the configured 8.25% belongs to nowhere any of these workbooks priced,
     * which is between 6.5% and 7.5%.
     *
     * @param  array<string, mixed>  $engineEstimate
     */
    private function taxPercent(array $engineEstimate, ProjectRateBook $rateBook, PriceBookLookup $priceBook): float
    {
        $rate = (float) ($engineEstimate['tax_rate'] ?? 0);

        if ($rate > 0) {
            // Stored as a fraction by the engine (0.15 → 15%).
            return round($rate <= 1 ? $rate * 100 : $rate, 2);
        }

        $bidRates = $rateBook->isPopulated() ? $rateBook->bidRates() : $priceBook->bidRates();

        return round($bidRates['tax_pct'], 2);
    }

    /**
     * Overheads and profit, as one markup.
     *
     * The workbooks keep them apart — 10% overheads, then 12% profit on top —
     * and an estimate here has a single markup field. Compounding them is what
     * the bid sheets do, so that is what is reproduced: 1.10 × 1.12 is a 23.2%
     * markup, not 22%. Read off this project's own workbook first, the price
     * book once it has nothing to say.
     */
    private function markupPercent(ProjectRateBook $rateBook, PriceBookLookup $priceBook): float
    {
        $rates = $rateBook->isPopulated() ? $rateBook->bidRates() : $priceBook->bidRates();
        $overhead = $rates['overhead_pct'] / 100;
        $profit = $rates['profit_pct'] / 100;

        if ($overhead <= 0 && $profit <= 0) {
            return (float) config('ai.estimating.markup_pct');
        }

        return round(((1 + $overhead) * (1 + $profit) - 1) * 100, 2);
    }

    /**
     * What an hour of labour costs.
     *
     * The client's own rate wins outright when they have set one — set from
     * the client's own record, it is what every one of their estimates is
     * meant to bill an hour at, regardless of what any workbook says a
     * vendor once charged. Only once they have not is it read off this
     * project's own uploaded workbook, then the price book.
     */
    private function effectiveLaborRate(ProjectRateBook $rateBook, PriceBookLookup $priceBook, ?float $clientLaborRate): float
    {
        if ($clientLaborRate !== null) {
            return $clientLaborRate;
        }

        return $rateBook->isPopulated() ? $rateBook->laborRate() : $priceBook->laborRate();
    }

    /** The client's own labor rate override for this project, if they have set one. */
    private function clientLaborRate(Project $project): ?float
    {
        $rate = $project->clientRecord?->labor_rate;

        return $rate !== null ? (float) $rate : null;
    }

    /**
     * Rows this project's own rate list has priced but nothing on the
     * drawing claimed — a vendor bid on them, so they still belong in front
     * of the estimator, at zero quantity with their price already filled in,
     * rather than a workbook row that silently never made it onto the
     * estimate.
     *
     * @param  list<int>  $matchedRateItemIds
     */
    private function writeUnmatchedRateBookLines(Estimate $estimate, ProjectRateBook $rateBook, array $matchedRateItemIds): void
    {
        $leftover = $rateBook->unmatchedItems($matchedRateItemIds);

        if ($leftover->isEmpty()) {
            return;
        }

        $position = (int) ($estimate->items()->max('position') ?? -1) + 1;

        foreach ($leftover as $item) {
            $estimate->items()->create([
                'category' => $this->categoryFor($item->description),
                'description' => $item->description,
                'unit' => $item->unit,
                'quantity' => 0,
                'unit_cost' => round((float) ($item->unit_material_cost ?? 0), 4),
                'source' => 'ai',
                'pricing_source' => 'vendor-rate-list',
                'project_rate_item_id' => $item->id,
                'pricing_confidence' => 'exact',
                'position' => $position++,
            ]);
        }
    }

    /** The budget the project was opened with, if the estimator set one. */
    private function projectTarget(AiResult $result): ?float
    {
        $target = $result->project->estimate_target_total ?? null;

        return $target !== null ? (float) $target : null;
    }

    /**
     * Keeps a job's budget the figure its own estimate came to.
     *
     * A job's budget is never typed on the create-job form — it is this,
     * always, kept in step every time the estimate behind it is priced,
     * repriced, or edited by hand, whether that happens before or after the
     * job itself exists. Public so `EstimateDetailController` can call it
     * after a manual line edit too, not only from this class's own takeoff-time
     * pricing.
     */
    public function syncJobBudget(Estimate $estimate): void
    {
        $estimate->job?->update(['budget' => $estimate->amount]);
    }

    /**
     * Scales every AI-priced line so the estimate's grand total lands exactly
     * on the project's budget, whether its rates came from the price book or
     * the engine's own guess.
     *
     * Manual lines are never touched — they are the estimator's own figures,
     * not the takeoff's to rewrite — so only the AI portion of the subtotal is
     * squeezed or stretched to make room for them. Each line keeps its share
     * of the subtotal it already had; a run priced mostly in labor stays
     * mostly labor after scaling.
     */
    private function scaleToTarget(Estimate $estimate, ?float $target): void
    {
        if ($target === null || $target <= 0) {
            return;
        }

        $items = $estimate->items()->orderBy('position')->get();
        // A zero-quantity line (a rate list row nothing on the drawing
        // claimed) has no share of the subtotal to scale — including it
        // would risk the last-item rounding remainder landing on a line
        // whose quantity can't absorb it.
        $aiItems = $items->where('source', 'ai')
            ->filter(fn (EstimateItem $item) => (float) $item->quantity > 0)
            ->values();

        if ($aiItems->isEmpty()) {
            return;
        }

        $aiSubtotal = (float) $aiItems->sum('total');

        if ($aiSubtotal <= 0) {
            return;
        }

        $manualSubtotal = (float) $items->where('source', '!=', 'ai')->sum('total');
        $divisor = (1 + (float) $estimate->markup_pct / 100) * (1 + (float) $estimate->tax_pct / 100);
        $targetSubtotal = $divisor > 0 ? round($target / $divisor, 2) : $target;
        $aiTargetSubtotal = round($targetSubtotal - $manualSubtotal, 2);

        // The estimator's own lines already account for the whole budget (or
        // more) — nothing left to hand the AI lines without one going negative.
        if ($aiTargetSubtotal <= 0) {
            return;
        }

        $this->distributeProportionally($aiItems, $aiTargetSubtotal, $aiSubtotal);

        $estimate->recalculateTotals();
        $estimate->refresh();

        /*
         * Two rounded percentages stacked on a rounded subtotal can leave the
         * grand total a cent or two off the figure the project was budgeted
         * at. Absorb that sliver into tax — the one line nobody reads down to
         * the cent — so the number the client sees matches exactly.
         */
        $residual = round($target - (float) $estimate->grand_total, 2);

        if ($residual !== 0.0) {
            $estimate->update([
                'tax_total' => round((float) $estimate->tax_total + $residual, 2),
                'grand_total' => $target,
            ]);
        }
    }

    /**
     * Rescales a set of lines to a new subtotal, preserving each line's share
     * of the old one. The last line absorbs whatever the per-line rounding
     * leaves over, so the lines sum to the target exactly.
     *
     * @param  Collection<int, EstimateItem>  $items
     */
    private function distributeProportionally(Collection $items, float $targetSubtotal, float $currentSubtotal): void
    {
        $count = $items->count();
        $allocated = 0.0;

        $items->each(function (EstimateItem $item, int $index) use (&$allocated, $count, $targetSubtotal, $currentSubtotal) {
            $isLast = $index === $count - 1;
            $share = $isLast
                ? round($targetSubtotal - $allocated, 2)
                : round(((float) $item->total / $currentSubtotal) * $targetSubtotal, 2);

            $quantity = (float) $item->quantity;

            if ($quantity > 0) {
                $item->update(['unit_cost' => round($share / $quantity, 4)]);
            }

            $allocated += $share;
        });
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
                ."so these lines come from this project's own uploaded vendor rate list. {$stage}. Review every rate.";
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
