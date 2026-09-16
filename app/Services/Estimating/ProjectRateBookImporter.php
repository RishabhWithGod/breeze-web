<?php

namespace App\Services\Estimating;

use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use Illuminate\Support\Facades\DB;
use SplFileInfo;
use Throwable;

/**
 * Reads a vendor rate list into one project's own rate book.
 *
 * Parsing itself is {@see RateListReader} — a workbook, a PDF, a Word
 * document, whatever a vendor happened to send. This class only decides
 * where the parsed rows land: always a single project, never pooled with
 * any other project's and never touching the shared price book at all.
 * Running it twice is safe: a file is identified by the hash of its
 * contents, and re-importing one for the same project replaces its lines
 * rather than doubling them.
 */
class ProjectRateBookImporter
{
    public function __construct(private readonly RateListReader $reader) {}

    /**
     * @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>}
     */
    public function parse(SplFileInfo $file, string $fileName): array
    {
        return $this->reader->parse($file, $fileName);
    }

    /**
     * Records one workbook's lines against `$projectId`.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $recap
     */
    public function store(SplFileInfo $file, string $fileName, array $lines, array $recap, int $projectId): ProjectRateImport
    {
        return DB::transaction(function () use ($file, $fileName, $lines, $recap, $projectId) {
            $hash = hash_file('sha256', $file->getPathname());

            // The same workbook again, for the same project: its lines are
            // replaced, not added to.
            ProjectRateImport::where('project_id', $projectId)->where('file_hash', $hash)->delete();

            $import = ProjectRateImport::create([
                'project_id' => $projectId,
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
                ProjectRateLine::insert(array_map(
                    fn (array $line) => [
                        'project_rate_import_id' => $import->id,
                        'project_id' => $projectId,
                        ...$line,
                    ],
                    $chunk,
                ));
            }

            return $import;
        });
    }

    /**
     * Rebuilds the quotable rates for `$projectId` from every line on record
     * for it. The median, not the mean — see `PriceBookImporter::rebuildItems()`
     * for why; the same reasoning holds for a project priced from more than
     * one workbook.
     */
    public function rebuildItems(int $projectId): int
    {
        $groups = ProjectRateLine::query()
            ->where('project_id', $projectId)
            ->selectRaw('match_key, unit, MAX(description) AS description, MAX(section) AS section, MAX(subsection) AS subsection, COUNT(*) AS samples')
            ->whereNotNull('unit')
            ->groupBy('match_key', 'unit')
            ->get();

        foreach ($groups as $group) {
            $lines = ProjectRateLine::where('project_id', $projectId)
                ->where('match_key', $group->match_key)
                ->where('unit', $group->unit)
                ->get(['unit_material_cost', 'unit_manhours']);

            $costs = $lines->pluck('unit_material_cost')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();
            $hours = $lines->pluck('unit_manhours')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();

            $item = ProjectRateItem::firstOrNew([
                'project_id' => $projectId,
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
                'unit_material_cost' => $this->median($costs->all()),
                'unit_manhours' => $this->median($hours->all()),
            ]);

            $item->save();
        }

        return $groups->count();
    }

    /** Parses, stores and rebuilds in one call — a single import's entry point. */
    public function importWorkbook(SplFileInfo $file, string $fileName, int $projectId): ProjectRateImport
    {
        ['lines' => $lines, 'recap' => $recap] = $this->parse($file, $fileName);

        $import = $this->store($file, $fileName, $lines, $recap, $projectId);

        $this->rebuildItems($projectId);

        return $import;
    }

    /**
     * Several files into one project's rate book at once. Items are
     * rebuilt once at the end rather than after every file, so a rate seen
     * across files is one median rather than whatever the last file said.
     *
     * A file that cannot be read at all — the wrong format, a corrupt
     * upload — does not stop the others.
     *
     * @param  list<array{file: SplFileInfo, name: string}>  $files
     * @return array{imported: list<ProjectRateImport>, empty: list<string>, failed: list<string>}
     */
    public function importWorkbooks(array $files, int $projectId): array
    {
        $imported = [];
        $empty = [];
        $failed = [];

        foreach ($files as $entry) {
            try {
                ['lines' => $lines, 'recap' => $recap] = $this->parse($entry['file'], $entry['name']);
                $import = $this->store($entry['file'], $entry['name'], $lines, $recap, $projectId);

                $import->line_count > 0 ? $imported[] = $import : $empty[] = $entry['name'];
            } catch (Throwable $e) {
                report($e);
                $failed[] = $entry['name'];
            }
        }

        if ($imported !== []) {
            $this->rebuildItems($projectId);
        }

        return ['imported' => $imported, 'empty' => $empty, 'failed' => $failed];
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
