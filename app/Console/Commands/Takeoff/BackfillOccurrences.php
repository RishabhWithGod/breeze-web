<?php

namespace App\Console\Commands\Takeoff;

use App\Models\AiResult;
use App\Models\SymbolReview;
use App\Services\Ai\AiResponseNormaliser;
use App\Services\Ai\LifecycleReader;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuilds `symbol_reviews.occurrences` for runs ingested before that column
 * existed, straight from the already-stored `original_payload` — no re-upload,
 * no new engine call for the detections themselves.
 *
 * The original AI response is never touched: this only recomputes the same
 * derived `occurrences` shape {@see AiResponseNormaliser::summaries()} already
 * produces for a fresh ingest, and writes it onto the matching existing row
 * (matched by its immutable `external_id`, so a rename/merge/split survives).
 * A row with no match in the freshly-normalised cards — split-off children,
 * manually-added symbols — is left untouched entirely.
 *
 * Purely a function of `original_payload`, so running this twice on the same
 * result produces byte-identical `occurrences` both times: nothing is ever
 * duplicated, appended to, or accumulated.
 */
class BackfillOccurrences extends Command
{
    protected $signature = 'takeoff:backfill-occurrences
        {--result= : Only this AiResult id}
        {--project= : Only AiResults belonging to this project id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild symbol_reviews.occurrences (and correct page) from each run\'s stored original AI response';

    public function handle(AiResponseNormaliser $normaliser, LifecycleReader $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = AiResult::query()->whereNotNull('original_payload');

        if ($resultId = $this->option('result')) {
            $query->where('id', (int) $resultId);
        }

        if ($projectId = $this->option('project')) {
            $query->where('project_id', (int) $projectId);
        }

        $results = $query->orderBy('id')->get();

        if ($results->isEmpty()) {
            $this->info('No matching AI results.');

            return self::SUCCESS;
        }

        $totals = ['results' => 0, 'rows_updated' => 0, 'page_sizes_fetched' => 0];

        foreach ($results as $result) {
            $totals['results']++;
            $this->processResult($result, $normaliser, $lifecycle, $dryRun, $totals);
        }

        $this->newLine();
        $this->table(
            ['Results processed', 'Rows updated', 'Page sizes fetched'],
            [[$totals['results'], $totals['rows_updated'], $totals['page_sizes_fetched']]],
        );

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /** @param  array{results: int, rows_updated: int, page_sizes_fetched: int}  $totals */
    private function processResult(
        AiResult $result,
        AiResponseNormaliser $normaliser,
        LifecycleReader $lifecycle,
        bool $dryRun,
        array &$totals,
    ): void {
        try {
            $normalised = $normaliser->normalise($result->original_payload);
        } catch (Throwable $e) {
            $this->warn("Result #{$result->id}: could not be normalised ({$e->getMessage()}), skipped.");

            return;
        }

        // Only symbol-type cards carry per-occurrence detail — a needs-review
        // card already has its own real page and bbox, nothing to backfill.
        $freshByExternalId = collect($normalised['cards'])
            ->where('origin', SymbolReview::ORIGIN_SYMBOL)
            ->keyBy('external_id');

        $existing = $result->reviews()
            ->where('origin', SymbolReview::ORIGIN_SYMBOL)
            ->get()
            ->keyBy('external_id');

        $updated = 0;

        foreach ($existing as $externalId => $review) {
            $card = $freshByExternalId->get($externalId);

            if ($card === null) {
                continue;
            }

            $occurrences = $card['occurrences'] ?? null;
            $page = $this->derivePage($occurrences);

            // Idempotent by construction: recomputed from the same immutable
            // payload every time, so a second run either writes nothing new
            // or writes the identical value it wrote before. Loose comparison
            // deliberately: MySQL's JSON column re-orders object keys on
            // storage, so a round-tripped array never strictly `===` the one
            // that was written, even when every key and value is identical.
            if ($review->occurrences == $occurrences && $review->page === $page) {
                continue;
            }

            $updated++;

            if (! $dryRun) {
                $review->updateQuietly(['occurrences' => $occurrences, 'page' => $page]);
            }
        }

        $totals['rows_updated'] += $updated;

        $sizesFetched = false;

        if (! $dryRun && blank($result->page_sizes) && filled($result->run_id)) {
            try {
                $sizes = $lifecycle->pageSizes($result->run_id);

                if ($sizes !== []) {
                    $result->updateQuietly(['page_sizes' => $sizes]);
                    $sizesFetched = true;
                }
            } catch (Throwable $e) {
                $this->warn("Result #{$result->id}: page dimensions could not be fetched ({$e->getMessage()}).");
            }
        }

        if ($sizesFetched) {
            $totals['page_sizes_fetched']++;
        }

        $this->line(
            "Result #{$result->id}: {$updated} row(s) updated"
            .($sizesFetched ? ', page sizes fetched' : '')
            .'.'
        );
    }

    /**
     * A single page number when every occurrence agrees on one, otherwise
     * null — never a fabricated default. Mirrors {@see SymbolReview::pageNumbers()}
     * but works from the freshly-normalised array shape rather than a
     * persisted model.
     *
     * @param  ?list<array<string, mixed>>  $occurrences
     */
    private function derivePage(?array $occurrences): ?int
    {
        if ($occurrences === null || $occurrences === []) {
            return null;
        }

        $pages = collect($occurrences)
            ->pluck('page')
            ->filter(fn ($page) => $page !== null)
            ->unique();

        return $pages->count() === 1 ? (int) $pages->first() : null;
    }
}
