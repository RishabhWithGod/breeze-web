<?php

namespace App\Console\Commands;

use App\Models\PriceBookImport;
use App\Models\PriceBookItem;
use App\Models\PriceBookLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use SplFileInfo;

/**
 * Reads the estimating workbooks into the price book.
 *
 * The sheets are written for a person, not a parser: the header sits on
 * whichever row the project name happened to leave free, section titles are
 * bare text in column A, and subtotal rows look like line items until you
 * notice they have no unit. So nothing here trusts a fixed row number — the
 * header is found by its own column names, and a row counts as priced only if
 * it has a description, a quantity and a unit together.
 *
 * Running it twice is safe: a workbook is identified by the hash of its
 * contents, and re-importing one replaces its lines rather than doubling them.
 */
class ImportPriceBook extends Command
{
    protected $signature = 'pricebook:import
        {path : A workbook, or a folder of them}
        {--fresh : Remove every previous import first}
        {--dry-run : Read and report, write nothing}';

    protected $description = 'Import estimating workbooks into the price book';

    /** The Estimate sheet columns, by the header text that names them. */
    private const COLUMNS = [
        'sr_no' => 'SR. NO.',
        'dwg_no' => 'DWG. NO.',
        'detail_no' => 'DETAIL NO.',
        'description' => 'DESCRIPTION',
        'quantity' => 'QUANTITY',
        'wastage' => 'WASTAGE',
        'quantity_with_wastage' => 'QTY WITH WASTAGE',
        'unit' => 'UNIT',
        'unit_material_cost' => 'UNIT MATERIAL COST',
        'material_cost' => 'MATERIAL COST',
        'manhour_rate' => 'MANHOUR RATE',
        'unit_manhours' => 'UNIT MANHOURS',
        'total_manhours' => 'TOTAL MANHOURS',
        'manhours_cost' => 'MANHOURS COST',
        'total_cost' => 'TOTAL COST',
    ];

    public function handle(): int
    {
        $files = $this->workbooks($this->argument('path'));

        if ($files === []) {
            $this->error('No .xlsx files found at that path.');

            return self::FAILURE;
        }

        if ($this->option('fresh') && ! $this->option('dry-run')) {
            // Lines cascade; the derived items are rebuilt from scratch below.
            PriceBookImport::query()->delete();
            PriceBookItem::query()->delete();
            $this->warn('Previous imports removed.');
        }

        $totalLines = 0;

        foreach ($files as $file) {
            $lines = $this->readWorkbook($file);
            $recap = $this->readRecap($file);
            $totalLines += count($lines);

            $this->line(sprintf(
                '%-50s %4d lines   %s',
                mb_strimwidth($file->getFilename(), 0, 48, '…'),
                count($lines),
                $recap['project_name'] ?? '',
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $this->store($file, $lines, $recap);
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$totalLines} priced lines across ".count($files).' workbook(s). Nothing written.');

            return self::SUCCESS;
        }

        $items = $this->rebuildItems();

        $this->newLine();
        $this->info(sprintf(
            'Imported %d priced lines from %d workbook(s) → %d price book items.',
            $totalLines,
            count($files),
            $items,
        ));

        return self::SUCCESS;
    }

    /** @return list<SplFileInfo> */
    private function workbooks(string $path): array
    {
        if (is_file($path)) {
            return [new SplFileInfo($path)];
        }

        $found = glob(rtrim($path, '/').'/*.xlsx') ?: [];
        sort($found);

        // Excel's own lock files start with "~$" and are not workbooks.
        return array_values(array_map(
            fn (string $f) => new SplFileInfo($f),
            array_filter($found, fn (string $f) => ! str_starts_with(basename($f), '~$')),
        ));
    }

    /**
     * The priced lines of the Estimate sheet.
     *
     * @return list<array<string, mixed>>
     */
    private function readWorkbook(SplFileInfo $file): array
    {
        $rows = $this->rows($file, 'Estimate');
        $map = null;
        $section = null;
        $subsection = null;
        $lines = [];

        foreach ($rows as $number => $row) {
            if ($map === null) {
                $map = $this->headerMap($row);

                continue;
            }

            $cell = fn (string $key) => isset($map[$key]) ? ($row[$map[$key]] ?? null) : null;

            $description = PriceBookLine::clean((string) $cell('description'));
            $quantity = $this->number($cell('quantity'));
            $unit = trim((string) $cell('unit'));

            /*
             * A priced line has all three. Subtotal rows carry money but no
             * unit; heading rows carry a name in column A and nothing else.
             */
            if ($description !== '' && $quantity !== null && $unit !== '') {
                $lines[] = [
                    'section' => $section,
                    'subsection' => $subsection,
                    'sr_no' => $this->text($cell('sr_no'), 24),
                    'dwg_no' => $this->text($cell('dwg_no'), 60),
                    'detail_no' => $this->text($cell('detail_no'), 60),
                    'description' => $description,
                    'quantity' => $quantity,
                    'wastage' => $this->number($cell('wastage')),
                    'quantity_with_wastage' => $this->number($cell('quantity_with_wastage')),
                    'unit' => mb_substr($unit, 0, 24),
                    'unit_material_cost' => $this->number($cell('unit_material_cost')),
                    'material_cost' => $this->number($cell('material_cost')),
                    'manhour_rate' => $this->number($cell('manhour_rate')),
                    'unit_manhours' => $this->number($cell('unit_manhours')),
                    'total_manhours' => $this->number($cell('total_manhours')),
                    'manhours_cost' => $this->number($cell('manhours_cost')),
                    'total_cost' => $this->number($cell('total_cost')),
                    'source_row' => $number,
                    'match_key' => PriceBookLine::keyFor($description),
                ];

                continue;
            }

            /*
             * Otherwise it may be a heading. The sheets use two levels and mark
             * neither: a top-level section (DISTRIBUTION, BRANCH WIRING) is one
             * of the names the Bid Recap lists, and anything else in column A
             * on its own is the group under it (BREAKERS, CONDUITS - LIGHTING).
             */
            $heading = trim((string) ($row[0] ?? ''));

            if ($heading === '' || $description !== '' || $quantity !== null) {
                continue;
            }

            $heading = trim(preg_replace('/\s+/', ' ', $heading));

            if (mb_strlen($heading) > 60 || $heading !== mb_strtoupper($heading)) {
                continue;
            }

            if ($this->looksLikeSection($heading)) {
                $section = $heading;
                $subsection = null;
            } else {
                $subsection = $heading;
            }
        }

        return $lines;
    }

    /** The top-level groups the Bid Recap totals by. */
    private function looksLikeSection(string $heading): bool
    {
        return in_array($heading, [
            'DISTRIBUTION',
            'BRANCH WIRING',
            'WIRING DEVICES',
            'LIGHTING FIXTURES',
            'LIGHTING CONTROLS',
            'FIRE ALARM',
            'LOW VOLTAGE',
            'GROUNDING',
            'DEMOLITION',
            'EQUIPMENT',
            'MISCELLANEOUS',
        ], true) || str_starts_with($heading, 'ROUGH-IN');
    }

    /**
     * The Bid Recap sheet's totals and rates.
     *
     * Read by label rather than by cell, because the block shifts down as
     * sections are added: the numbers sit somewhere to the right of a phrase
     * like "TOTAL MATERIAL COST", and the rightmost number on that row is the
     * one that matters.
     *
     * @return array<string, mixed>
     */
    private function readRecap(SplFileInfo $file): array
    {
        $rows = $this->rows($file, 'Bid Recap & Summary');

        $recap = ['project_name' => null];
        $labels = [
            'material_cost' => 'TOTAL MATERIAL COST',
            'labor_cost' => 'TOTAL LABOR COST',
            'total_cost' => 'TOTAL COST',
            'base_bid_price' => 'BASE BID PRICE',
            'total_manhours' => 'TOTAL MANHOURS WITH SUPERVIS',
            'electrician_rate' => 'ELECTRICIAN RATE',
            'supervisor_rate' => 'SUPERVISOR RATE',
            'unskilled_rate' => 'UNSKILLED LABOR RATE',
            'composite_labor_rate' => 'COMPOSITE LABOR RATE',
        ];

        foreach ($rows as $row) {
            $joined = mb_strtoupper(trim(implode(' ', array_map(
                fn ($c) => is_string($c) ? $c : '',
                $row,
            ))));

            if ($recap['project_name'] === null && str_contains($joined, 'PROJECT NAME:')) {
                $recap['project_name'] = $this->projectName($row);
            }

            foreach ($labels as $key => $label) {
                // First hit wins: "TOTAL COST" also appears inside the longer
                // "TOTAL COST WITH OVERHEADS", which is a different figure.
                if (! isset($recap[$key]) && $this->rowHasLabel($row, $label)) {
                    $recap[$key] = $this->lastNumber($row);
                }
            }

            if (! isset($recap['material_tax_pct']) && $this->rowHasLabel($row, 'MATERIAL SALES TAX')) {
                $recap['material_tax'] = $this->lastNumber($row);
                $recap['material_tax_pct'] = $this->percent($row);
            }

            if (! isset($recap['overhead_pct']) && $this->rowHasLabel($row, 'OVERHEADS @')) {
                $recap['overhead_pct'] = $this->percent($row);
            }

            if (! isset($recap['profit_pct']) && $this->rowHasLabel($row, 'PROFIT @')) {
                $recap['profit_pct'] = $this->percent($row);
            }
        }

        return $recap;
    }

    /** @param array<int, mixed> $row */
    private function rowHasLabel(array $row, string $label): bool
    {
        foreach ($row as $cell) {
            if (is_string($cell) && str_contains(mb_strtoupper(trim($cell)), $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A rate written as a fraction, as a percentage.
     *
     * The sheets hold 0.075 for the tax and 0.1 for overheads — fractions — but
     * an estimator reading the column sees "7.5%". Anything already above 1 is
     * taken as a percentage and left alone.
     *
     * @param  array<int, mixed>  $row
     */
    private function percent(array $row): ?float
    {
        $sawZero = false;

        foreach ($row as $cell) {
            $value = $this->number($cell);

            if ($value === null) {
                continue;
            }

            if ($value > 0 && $value < 1) {
                return round($value * 100, 3);
            }

            $sawZero = $sawZero || $value === 0.0;
        }

        /*
         * Nought is an answer, not a gap. A school district is tax-exempt and
         * its workbook says so with a zero — recording that as "unknown" would
         * later let a default rate be applied to a job that must not carry one.
         */
        return $sawZero ? 0.0 : null;
    }

    /** @param array<int, mixed> $row */
    private function lastNumber(array $row): ?float
    {
        $found = null;

        foreach ($row as $cell) {
            $value = $this->number($cell);

            // Skip the fraction beside a label ("OVERHEADS @ 0.1  5712.74"):
            // the money is the figure to the right of it.
            if ($value !== null && ! ($value > 0 && $value < 1)) {
                $found = $value;
            }
        }

        return $found;
    }

    /** @param array<int, mixed> $row */
    private function projectName(array $row): ?string
    {
        foreach ($row as $cell) {
            if (! is_string($cell) || ! str_contains(mb_strtoupper($cell), 'PROJECT NAME:')) {
                continue;
            }

            // The cell holds the name and the date on two lines.
            $name = trim(explode("\n", explode(':', $cell, 2)[1] ?? '')[0]);

            return $name === '' ? null : mb_substr($name, 0, 255);
        }

        return null;
    }

    /**
     * A sheet's rows, keyed by their real row number.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rows(SplFileInfo $file, string $sheetName): array
    {
        $reader = new Reader;
        $reader->open($file->getPathname());

        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== $sheetName) {
                continue;
            }

            foreach ($sheet->getRowIterator() as $number => $row) {
                $rows[$number] = array_map($this->value(...), $row->getCells());
            }

            break;
        }

        $reader->close();

        return $rows;
    }

    /**
     * What a cell holds, formula cells included.
     *
     * Most of these sheets are formulas — quantity with wastage is `=E6+(E6*F6)`,
     * material cost is `=I6*G6`, the manhour rate is `=$K$4`. Reading the
     * formula text instead of the number Excel last worked out would throw away
     * most of the workbook: whole columns would arrive empty and rows priced by
     * a formula would not look priced at all.
     */
    private function value(Cell $cell): mixed
    {
        return $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();
    }

    /**
     * Which column holds what, found by the header's own names.
     *
     * Returns null until the header row is reached, so the caller can skip
     * whatever the workbook put above it.
     *
     * @param  array<int, mixed>  $row
     * @return array<string, int>|null
     */
    private function headerMap(array $row): ?array
    {
        $cells = [];

        foreach ($row as $index => $cell) {
            if (is_string($cell)) {
                $cells[$index] = trim(preg_replace('/\s+/', ' ', mb_strtoupper($cell)));
            }
        }

        $values = array_values($cells);

        if (! in_array('DESCRIPTION', $values, true) || ! in_array('QUANTITY', $values, true)) {
            return null;
        }

        $map = [];

        foreach (self::COLUMNS as $key => $header) {
            $found = array_search($header, $cells, true);

            if ($found !== false) {
                $map[$key] = $found;
            }
        }

        return $map;
    }

    /** Excel gives numbers, strings and the odd formula error; only numbers count. */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $clean = str_replace([',', '$', ' '], '', trim($value));

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(is_string($value) ? $value : (string) (is_float($value) ? (int) $value : $value));

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $recap
     */
    private function store(SplFileInfo $file, array $lines, array $recap): void
    {
        DB::transaction(function () use ($file, $lines, $recap) {
            $hash = hash_file('sha256', $file->getPathname());

            // The same workbook again: its lines are replaced, not added to.
            PriceBookImport::where('file_hash', $hash)->delete();

            $import = PriceBookImport::create([
                'file_name' => $file->getFilename(),
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
                    fn (array $line) => ['price_book_import_id' => $import->id, ...$line],
                    $chunk,
                ));
            }
        });
    }

    /**
     * Rebuilds the quotable rates from every line on record.
     *
     * The median, not the mean: one job that bought a fixture at ten times the
     * usual price would drag an average with it, and the rate this system
     * quotes has to be the one it usually pays. Rates somebody has pinned are
     * left exactly as they set them — only the range around them is refreshed.
     */
    private function rebuildItems(): int
    {
        $groups = PriceBookLine::query()
            ->selectRaw('match_key, unit, MAX(description) AS description, MAX(section) AS section, MAX(subsection) AS subsection, COUNT(*) AS samples')
            ->whereNotNull('unit')
            ->groupBy('match_key', 'unit')
            ->get();

        foreach ($groups as $group) {
            $lines = PriceBookLine::where('match_key', $group->match_key)
                ->where('unit', $group->unit)
                ->get(['unit_material_cost', 'unit_manhours']);

            $costs = $lines->pluck('unit_material_cost')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();
            $hours = $lines->pluck('unit_manhours')->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)->values();

            $item = PriceBookItem::firstOrNew([
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
