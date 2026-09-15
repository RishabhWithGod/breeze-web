<?php

namespace App\Services\PriceBook;

use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use App\Services\Estimating\WorkbookReader;
use Illuminate\Support\Facades\DB;
use SplFileInfo;
use Throwable;

/**
 * Reads an estimating workbook into the price book.
 *
 * Parsing itself is {@see WorkbookReader} — this class only decides where a
 * parsed workbook's rows land. Seeded today by the `pricebook:import`
 * command only (`user_id` null, the universal book) — the web upload on the
 * project form no longer feeds this; see `ProjectRateBookImporter` for that.
 * Running it twice is safe: a workbook is identified by the hash of its
 * contents, and re-importing one replaces its lines rather than doubling
 * them.
 */
class PriceBookImporter
{
    public function __construct(private readonly WorkbookReader $reader) {}

    /**
     * Parses both sheets of a workbook. Reads nothing else and writes nothing —
     * callers decide whether to {@see store()} what came back.
     *
     * @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>}
     */
    public function parse(SplFileInfo $file): array
    {
        return $this->reader->parse($file);
    }

    /**
     * Records one workbook's lines against `$userId` — null for the universal
     * book, or the id of whoever uploaded it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $recap
     */
    public function store(SplFileInfo $file, string $fileName, array $lines, array $recap, ?int $userId): PriceBookImport
    {
        return DB::transaction(function () use ($file, $fileName, $lines, $recap, $userId) {
            $hash = hash_file('sha256', $file->getPathname());

            // The same workbook again, from the same owner: its lines are
            // replaced, not added to.
            PriceBookImport::where('user_id', $userId)->where('file_hash', $hash)->delete();

            $import = PriceBookImport::create([
                'user_id' => $userId,
                'file_name' => $fileName,
                'file_hash' => $hash,
                'project_name' => $recap['project_name'] ?? null,
                'material_cost' => $recap['material_cost'] ?? null,
                'labor_cost' => $recap['labor_cost'] ?? null,
                'material_tax' => $recap['material_tax'] ?? null,
                'total_cost' => $recap['total_cost'] ?? null,
                'base_bid_price' => $recap['base_bid_price'] ?? null,
                'material_tax_pct' => $recap['material_tax_pct'] ?? null,
                'overhead_pct' => $recap['overhead_pct'] ?? null,
                'profit_pct' => $recap['profit_pct'] ?? null,
                'electrician_rate' => $recap['electrician_rate'] ?? null,
                'supervisor_rate' => $recap['supervisor_rate'] ?? null,
                'unskilled_rate' => $recap['unskilled_rate'] ?? null,
                'composite_labor_rate' => $recap['composite_labor_rate'] ?? null,
                'total_manhours' => $recap['total_manhours'] ?? null,
                'line_count' => count($lines),
                'imported_at' => now(),
            ]);

            foreach (array_chunk($lines, 200) as $chunk) {
                PriceBookLine::insert(array_map(
                    fn (array $line) => [
                        'price_book_import_id' => $import->id,
                        'user_id' => $userId,
                        ...$line,
                    ],
                    $chunk,
                ));
            }

            return $import;
        });
    }

    /**
     * Rebuilds the quotable rates for `$userId`'s book from every line on
     * record for it.
     *
     * The median, not the mean: one job that bought a fixture at ten times the
     * usual price would drag an average with it, and the rate this system
     * quotes has to be the one it usually pays. Rates somebody has pinned are
     * left exactly as they set them — only the range around them is refreshed.
     */
    public function rebuildItems(?int $userId): int
    {
        $groups = PriceBookLine::query()
            ->where('user_id', $userId)
            ->selectRaw('match_key, unit, MAX(description) AS description, MAX(section) AS section, MAX(subsection) AS subsection, COUNT(*) AS samples')
            ->whereNotNull('unit')
            ->groupBy('match_key', 'unit')
            ->get();

        foreach ($groups as $group) {
            $lines = PriceBookLine::where('user_id', $userId)
                ->where('match_key', $group->match_key)
                ->where('unit', $group->unit)
                ->get(['unit_material_cost', 'unit_manhours']);

            $costs = $lines->pluck('unit_material_cost')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();
            $hours = $lines->pluck('unit_manhours')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();

            $item = PriceBookItem::firstOrNew([
                'user_id' => $userId,
                'match_key' => $group->match_key,
                'unit' => $group->unit,
            ]);

            $item->fill([
                'description' => mb_substr((string) $group->description, 0, 255),
                'section' => $group->section,
                'subsection' => $group->subsection,
                'sample_count' => (int) $group->samples,
                'min_material_cost' => $costs->min(),
                'max_material_cost' => $costs->max(),
                'min_manhours' => $hours->min(),
                'max_manhours' => $hours->max(),
                'last_seen_at' => now(),
            ]);

            if (! $item->is_pinned) {
                $item->unit_material_cost = $this->median($costs->all());
                $item->unit_manhours = $this->median($hours->all());
            }

            $item->save();
        }

        return $groups->count();
    }

    /** Parses, stores and rebuilds in one call — a single import's entry point. */
    public function importWorkbook(SplFileInfo $file, string $fileName, ?int $userId): PriceBookImport
    {
        ['lines' => $lines, 'recap' => $recap] = $this->parse($file);

        $import = $this->store($file, $fileName, $lines, $recap, $userId);

        $this->rebuildItems($userId);

        return $import;
    }

    /**
     * Several workbooks into one book at once. Items are rebuilt once at the
     * end rather than after every file, the same shape `pricebook:import`
     * uses for a folder of them, so a rate seen across files is still one
     * median rather than whatever the last file happened to say.
     *
     * A workbook that cannot be read at all does not stop the others —
     * a bad file in a batch of twenty must not cost the other nineteen.
     *
     * @param  list<array{file: SplFileInfo, name: string}>  $files
     * @return array{imported: list<PriceBookImport>, empty: list<string>, failed: list<string>}
     */
    public function importWorkbooks(array $files, ?int $userId): array
    {
        $imported = [];
        $empty = [];
        $failed = [];

        foreach ($files as $entry) {
            try {
                ['lines' => $lines, 'recap' => $recap] = $this->parse($entry['file']);
                $import = $this->store($entry['file'], $entry['name'], $lines, $recap, $userId);

                $import->line_count > 0 ? $imported[] = $import : $empty[] = $entry['name'];
            } catch (Throwable $e) {
                report($e);
                $failed[] = $entry['name'];
            }
        }

        if ($imported !== []) {
            $this->rebuildItems($userId);
        }

        return ['imported' => $imported, 'empty' => $empty, 'failed' => $failed];
    }

    /** Removes every import and item for `$userId`'s book — the CLI's `--fresh`. */
    public function resetBook(?int $userId): void
    {
        // Lines cascade off their import; items are rebuilt from scratch.
        PriceBookImport::where('user_id', $userId)->delete();
        PriceBookItem::where('user_id', $userId)->delete();
    }

    /** @param list<float> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
