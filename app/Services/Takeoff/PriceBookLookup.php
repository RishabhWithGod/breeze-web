<?php

namespace App\Services\Takeoff;

use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Matches a symbol on a drawing to the rate the company actually charges for it.
 *
 * The drawing gives a tag and the estimator's workbook gives the thing: a
 * lighting plan says `EM2`, and the schedule behind it says "EM2, NEW BATTERY
 * 2/HEAD EM FIXTURE" at $60.00 and 1.25 hours. So a match is worth more than a
 * price — it is also the full description, which is what the estimate should
 * read rather than the two letters the drawing had room for.
 *
 * Four ways in, tried in order, because a near-miss priced silently is worse
 * than no match at all:
 *
 *   1. the same name, normalised
 *   2. the schedule tag the description leads with — `EM2` finding `EM2, NEW …`
 *   3. every word of the symbol somewhere in the description
 *   4. nothing, said out loud — the caller falls back and marks the line
 *
 * Nothing here guesses a rate. An item the price book has never seen comes back
 * unmatched, and the estimate says so on the line itself.
 *
 * Scoped to one user's own book via {@see forUser()} — matching reads that
 * user's own uploaded rate list where they have one, and the shared universal
 * book where they do not, exactly the way {@see laborRate()} and
 * {@see bidRates()} already fall back. Nothing here is a *first* choice on an
 * estimate, though: this is the fallback a symbol reaches only once a
 * project's own vendor rate list has had nothing to say about it.
 */
class PriceBookLookup
{
    /** How long a resolved lookup is kept. Imports are rare; estimates are not. */
    private const CACHE_TTL = 600;

    /** @var Collection<int, PriceBookItem>|null */
    private ?Collection $items = null;

    /** Whose book this instance reads — null means the universal book. */
    private ?int $userId = null;

    /**
     * A copy of this lookup scoped to one user's own price book.
     *
     * A clone rather than a mutation: the container may hand the unscoped
     * instance to more than one collaborator in the same request, and scoping
     * one caller's copy must not leak into another's.
     */
    public function forUser(?int $userId): self
    {
        $scoped = clone $this;
        $scoped->userId = $userId;
        $scoped->items = null;

        return $scoped;
    }

    /**
     * The company's rate for this symbol, or null when it has never priced one.
     *
     * @return array{
     *     item: PriceBookItem,
     *     description: string,
     *     unit: string,
     *     unit_cost: float|null,
     *     labor_hours: float|null,
     *     confidence: string,
     * }|null
     */
    public function find(string $symbolName): ?array
    {
        $name = PriceBookLine::keyFor($symbolName);

        if ($name === '') {
            return null;
        }

        $item = $this->exact($name)
            ?? $this->byScheduleTag($name)
            ?? $this->byWords($name);

        if (! $item) {
            return null;
        }

        return [
            'item' => $item,
            /*
             * The workbook's own wording, not the drawing's. This is the point
             * of matching: "EM2" becomes "EM2, NEW BATTERY 2/HEAD EM FIXTURE"
             * on the estimate the client reads.
             */
            'description' => $item->description,
            'unit' => $item->unit,
            'unit_cost' => $item->unit_material_cost === null ? null : (float) $item->unit_material_cost,
            'labor_hours' => $item->unit_manhours === null ? null : (float) $item->unit_manhours,
            'confidence' => $item->confidence ?? 'exact',
        ];
    }

    /** The same item, written the same way. */
    private function exact(string $key): ?PriceBookItem
    {
        return $this->best($this->all()->where('match_key', $key), 'exact');
    }

    /**
     * The tag a fixture schedule leads with.
     *
     * Descriptions in the workbooks are written `<TAG>, <what it is>, <catalogue
     * number>` — "X3, SINGLE FACE EXIT FIXTURE W/ HEADS". A drawing labels the
     * same fixture `X3` and nothing else, so the tag is the only thing the two
     * have in common.
     *
     * Only for something that reads like a tag: one short token of letters and
     * digits. "SWITCH" must never be treated as one, or it would claim the
     * first description that happens to start with it.
     */
    private function byScheduleTag(string $key): ?PriceBookItem
    {
        if (! preg_match('/^[A-Z]{1,3}[0-9]{0,3}[A-Z]?$/', $key) || mb_strlen($key) > 5) {
            return null;
        }

        $matches = $this->all()->filter(function (PriceBookItem $item) use ($key) {
            $description = mb_strtoupper($item->description);

            // The comma is what makes it a tag rather than a first word.
            return str_starts_with($description, $key.',')
                || str_starts_with($description, $key.' ,');
        });

        return $this->best($matches, 'tag');
    }

    /**
     * Every word of the symbol's name, somewhere in the item's description.
     *
     * "Duplex Receptacle" finds "DUPLEX RECEPTACLE"; "Junction Box" finds
     * "JUNCTION BOX FOR TROFFER". Whole words only — a substring search would
     * let "SW" match "SWITCHBOARD", which is a different thing at fifty times
     * the price.
     */
    private function byWords(string $key): ?PriceBookItem
    {
        $words = array_values(array_filter(
            preg_split('/[^A-Z0-9\/#"\']+/', $key) ?: [],
            // One-letter fragments carry no meaning and match everything.
            fn (string $word) => mb_strlen($word) > 1,
        ));

        if ($words === []) {
            return null;
        }

        $matches = $this->all()->filter(function (PriceBookItem $item) use ($words) {
            $description = mb_strtoupper($item->description);

            foreach ($words as $word) {
                if (! preg_match('/(?<![A-Z0-9])'.preg_quote($word, '/').'(?![A-Z0-9])/', $description)) {
                    return false;
                }
            }

            return true;
        });

        return $this->best($matches, 'words');
    }

    /**
     * Of the candidates, the one worth quoting.
     *
     * A rate priced on sixty jobs is a rate; one priced once is a note. And a
     * shorter description is the plainer item — "JUNCTION BOX" over "JUNCTION
     * BOX FOR TROFFER" — because the extra words are a narrower case than the
     * drawing asked for.
     *
     * @param  Collection<int, PriceBookItem>  $candidates
     */
    private function best(Collection $candidates, string $confidence): ?PriceBookItem
    {
        $item = $candidates
            ->sortBy([
                /*
                 * A priced item first. Several of these workbooks carry lines
                 * that are labour only — a fixture the owner supplied, a sensor
                 * quoted elsewhere — and quoting one of those for a device that
                 * has to be bought would leave its material out of the bid
                 * entirely.
                 */
                fn (PriceBookItem $i) => match (true) {
                    $i->unit_material_cost !== null => 0,
                    $i->unit_manhours !== null => 1,
                    default => 2,
                },
                fn (PriceBookItem $i) => -$i->sample_count,
                fn (PriceBookItem $i) => mb_strlen($i->description),
            ])
            ->first();

        if ($item) {
            // Carried on the model rather than returned separately, so the
            // caller can say on the line how sure the match was.
            $item->confidence = $confidence;
        }

        return $item;
    }

    /**
     * This user's own uploaded items, or the universal book while they have
     * none — never another user's, and never both pooled together.
     *
     * @return Collection<int, PriceBookItem>
     */
    private function all(): Collection
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $columns = ['id', 'match_key', 'unit', 'description', 'section', 'subsection',
            'unit_material_cost', 'unit_manhours', 'sample_count'];

        $hasOwn = $this->userId !== null && PriceBookItem::query()->where('user_id', $this->userId)->exists();

        $query = $hasOwn
            ? PriceBookItem::query()->where('user_id', $this->userId)
            : PriceBookItem::query()->whereNull('user_id');

        return $this->items = $query->get($columns);
    }

    /**
     * What an hour of labour costs, as these jobs charged for it.
     *
     * The median of every priced line rather than of the ten workbooks: a job
     * with four hundred lines says more about the usual rate than one with
     * eighteen. Falls back to the configured rate while the price book is
     * empty, so an install with no import behind it still prices.
     */
    public function laborRate(): float
    {
        return (float) Cache::remember($this->cacheKey('labor-rate'), self::CACHE_TTL, function () {
            $rates = $this->scopedLines()
                ->whereNotNull('manhour_rate')
                ->where('manhour_rate', '>', 0)
                ->pluck('manhour_rate')
                ->map(fn ($rate) => (float) $rate)
                ->sort()
                ->values();

            return $rates->isEmpty()
                ? (float) config('ai.estimating.labor_rate')
                : $this->median($rates->all());
        });
    }

    /**
     * The rates these jobs were bid at: tax, overheads and profit.
     *
     * Read off the workbooks rather than from config, so an estimate raised
     * here carries the same percentages an estimator would have typed. Each
     * falls back to its configured value when nothing has been imported.
     *
     * @return array{tax_pct: float, overhead_pct: float, profit_pct: float}
     */
    public function bidRates(): array
    {
        return Cache::remember($this->cacheKey('bid-rates'), self::CACHE_TTL, function () {
            return [
                'tax_pct' => $this->medianOf('material_tax_pct')
                    ?? (float) config('ai.estimating.tax_pct'),
                'overhead_pct' => $this->medianOf('overhead_pct') ?? 0.0,
                'profit_pct' => $this->medianOf('profit_pct')
                    ?? (float) config('ai.estimating.markup_pct'),
            ];
        });
    }

    private function medianOf(string $column): ?float
    {
        $values = $this->scopedImports()
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values();

        return $values->isEmpty() ? null : $this->median($values->all());
    }

    /** Cache key for a metric, split by whose book it was read from. */
    private function cacheKey(string $metric): string
    {
        return 'price-book.'.$metric.'.'.($this->userId ?? 'universal');
    }

    /** This user's own priced lines, or the universal book while they have none. */
    private function scopedLines(): Builder
    {
        $hasOwn = $this->userId !== null && PriceBookLine::query()->where('user_id', $this->userId)->exists();

        return $hasOwn
            ? PriceBookLine::query()->where('user_id', $this->userId)
            : PriceBookLine::query()->whereNull('user_id');
    }

    /** This user's own imports, or the universal book while they have none. */
    private function scopedImports(): Builder
    {
        $hasOwn = $this->userId !== null && PriceBookImport::query()->where('user_id', $this->userId)->exists();

        return $hasOwn
            ? PriceBookImport::query()->where('user_id', $this->userId)
            : PriceBookImport::query()->whereNull('user_id');
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Whether there is a price book at all — the screens say so when there is not. */
    public function isPopulated(): bool
    {
        return $this->all()->isNotEmpty();
    }
}
