<?php

namespace App\Services\Estimating;

use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use Illuminate\Support\Collection;

/**
 * Matches a symbol on a drawing to the rate this project's own uploaded
 * vendor rate list charges for it — nothing shared, nothing pooled with any
 * other project's. {@see \App\Services\Takeoff\SymbolCatalog} is what falls
 * back to the price book when this book has nothing to say; this class knows
 * only its own project.
 *
 * The drawing gives a tag and the vendor's workbook gives the thing: a
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
 * Nothing here guesses a rate. An item this project's workbook has never seen
 * comes back unmatched, and the estimate says so on the line itself.
 */
class ProjectRateBook
{
    /** @var Collection<int, ProjectRateItem>|null */
    private ?Collection $items = null;

    /** Which project this instance reads. Set once via {@see forProject()}. */
    private ?int $projectId = null;

    /**
     * A copy of this lookup scoped to one project's own rate book.
     *
     * A clone rather than a mutation: the container may hand the unscoped
     * instance to more than one collaborator in the same request, and scoping
     * one caller's copy must not leak into another's.
     */
    public function forProject(int $projectId): self
    {
        $scoped = clone $this;
        $scoped->projectId = $projectId;
        $scoped->items = null;

        return $scoped;
    }

    /**
     * This project's rate for this symbol, or null when its workbook has
     * never priced one.
     *
     * @return array{
     *     item: ProjectRateItem,
     *     description: string,
     *     unit: string,
     *     unit_cost: float|null,
     *     labor_hours: float|null,
     *     confidence: string,
     * }|null
     */
    public function find(string $symbolName): ?array
    {
        $name = WorkbookReader::keyFor($symbolName);

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
    private function exact(string $key): ?ProjectRateItem
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
    private function byScheduleTag(string $key): ?ProjectRateItem
    {
        if (! preg_match('/^[A-Z]{1,3}[0-9]{0,3}[A-Z]?$/', $key) || mb_strlen($key) > 5) {
            return null;
        }

        $matches = $this->all()->filter(function (ProjectRateItem $item) use ($key) {
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
    private function byWords(string $key): ?ProjectRateItem
    {
        $words = array_values(array_filter(
            preg_split('/[^A-Z0-9\/#"\']+/', $key) ?: [],
            // One-letter fragments carry no meaning and match everything.
            fn (string $word) => mb_strlen($word) > 1,
        ));

        if ($words === []) {
            return null;
        }

        $matches = $this->all()->filter(function (ProjectRateItem $item) use ($words) {
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
     * A rate priced on several lines is a rate; one priced once is a note.
     * And a shorter description is the plainer item — "JUNCTION BOX" over
     * "JUNCTION BOX FOR TROFFER" — because the extra words are a narrower
     * case than the drawing asked for.
     *
     * @param  Collection<int, ProjectRateItem>  $candidates
     */
    private function best(Collection $candidates, string $confidence): ?ProjectRateItem
    {
        $item = $candidates
            ->sortBy([
                fn (ProjectRateItem $i) => match (true) {
                    $i->unit_material_cost !== null => 0,
                    $i->unit_manhours !== null => 1,
                    default => 2,
                },
                fn (ProjectRateItem $i) => -$i->sample_count,
                fn (ProjectRateItem $i) => mb_strlen($i->description),
            ])
            ->first();

        if ($item) {
            // Carried on the model rather than returned separately, so the
            // caller can say on the line how sure the match was.
            $item->confidence = $confidence;
        }

        return $item;
    }

    /** @return Collection<int, ProjectRateItem> */
    private function all(): Collection
    {
        if ($this->items !== null) {
            return $this->items;
        }

        if ($this->projectId === null) {
            return $this->items = collect();
        }

        return $this->items = ProjectRateItem::query()
            ->where('project_id', $this->projectId)
            ->get(['id', 'match_key', 'unit', 'description', 'section', 'subsection',
                'unit_material_cost', 'unit_manhours', 'sample_count']);
    }

    /**
     * What an hour of labour costs on this project, as its own workbook
     * charges for it. Falls back to the configured rate while nothing has
     * been uploaded, so a drawing with no rate list yet still prices.
     */
    public function laborRate(): float
    {
        if ($this->projectId === null) {
            return (float) config('ai.estimating.labor_rate');
        }

        $rates = ProjectRateLine::query()
            ->where('project_id', $this->projectId)
            ->whereNotNull('manhour_rate')
            ->where('manhour_rate', '>', 0)
            ->pluck('manhour_rate')
            ->map(fn ($rate) => (float) $rate)
            ->sort()
            ->values();

        return $rates->isEmpty()
            ? (float) config('ai.estimating.labor_rate')
            : $this->median($rates->all());
    }

    /**
     * The rates this project's own workbook was bid at: tax, overheads and
     * profit. Each falls back to its configured value while nothing has been
     * uploaded.
     *
     * @return array{tax_pct: float, overhead_pct: float, profit_pct: float}
     */
    public function bidRates(): array
    {
        return [
            'tax_pct' => $this->recap('material_tax_pct') ?? (float) config('ai.estimating.tax_pct'),
            'overhead_pct' => $this->recap('overhead_pct') ?? 0.0,
            'profit_pct' => $this->recap('profit_pct') ?? (float) config('ai.estimating.markup_pct'),
        ];
    }

    private function recap(string $column): ?float
    {
        if ($this->projectId === null) {
            return null;
        }

        $values = ProjectRateImport::query()
            ->where('project_id', $this->projectId)
            ->whereNotNull($column)
            ->pluck($column)
            ->map(fn ($value) => (float) $value)
            ->sort()
            ->values();

        return $values->isEmpty() ? null : $this->median($values->all());
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

    /** Whether this project has a rate list at all — the screens say so when there is not. */
    public function isPopulated(): bool
    {
        return $this->all()->isNotEmpty();
    }

    /**
     * This project's own rate list rows nothing on the drawing claimed — a
     * vendor priced them, but nothing the AI found (or the review approved)
     * matched them. They still belong on the estimate, at zero quantity and
     * already priced, for the estimator to put a real count against rather
     * than a workbook row that silently never made it in.
     *
     * @param  iterable<int>  $matchedItemIds
     * @return Collection<int, ProjectRateItem>
     */
    public function unmatchedItems(iterable $matchedItemIds): Collection
    {
        $excluded = collect($matchedItemIds)->all();

        return $this->all()
            ->reject(fn (ProjectRateItem $item) => in_array($item->id, $excluded, true))
            ->values();
    }
}
