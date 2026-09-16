<?php

namespace App\Services\Estimating;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;
use SplFileInfo;

/**
 * Reads an estimating workbook's two sheets into plain arrays — nothing
 * stored, nothing scoped to anyone's book. Shared by whichever importer
 * turns the result into rows: the parsing is the same regardless of whose
 * rate list is being read.
 *
 * The sheets are written for a person, not a parser: the header sits on
 * whichever row the project name happened to leave free, section titles are
 * bare text in column A, and subtotal rows look like line items until you
 * notice they have no unit. So nothing here trusts a fixed row number — the
 * header is found by its own column names, and a row counts as priced only if
 * it has a description, a quantity and a unit together.
 */
class WorkbookReader
{
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

    /**
     * Parses both sheets of an .xlsx workbook.
     *
     * @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>}
     */
    public function parse(SplFileInfo $file): array
    {
        return $this->fromGrids(
            $this->rows($file, 'Estimate'),
            $this->rows($file, 'Bid Recap & Summary'),
        );
    }

    /**
     * Parses two already-extracted grids of cells — the engine every format
     * shares. A PDF's table of numbers or a Word document's table becomes the
     * same shape once it has been split into rows and cells, and from there
     * it reads exactly like a workbook: the same header names, the same
     * "TOTAL MATERIAL COST" label hunted down the Bid Recap rows.
     *
     * @param  array<int, array<int, mixed>>  $itemRows
     * @param  array<int, array<int, mixed>>  $recapRows
     * @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>}
     */
    public function fromGrids(array $itemRows, array $recapRows): array
    {
        return [
            'lines' => $this->readWorkbook($itemRows),
            'recap' => $this->readRecap($recapRows),
        ];
    }

    /**
     * How an item's name becomes the key everything is matched on.
     *
     * Upper-cased, whitespace collapsed, and the stray punctuation the
     * sheets carry trimmed off the ends — the same conduit is written
     * `  3/4" CONDUIT - EMT` on one row and `3/4" Conduit - EMT ` on the
     * next, and they have to land on one rate.
     */
    public static function keyFor(string $description): string
    {
        return trim(mb_strtoupper(self::clean($description)), " \t\n\r\0\x0B-–—:.");
    }

    /**
     * A description as it should be stored and read.
     *
     * Excel writes a line break inside a cell as the literal text `_x000D_`,
     * and the fixture schedules are full of them — left alone they end up in
     * the middle of an item name and in the key it is matched on, so the
     * same fixture on two rows would never meet.
     */
    public static function clean(string $description): string
    {
        $decoded = preg_replace('/_x([0-9A-Fa-f]{4})_/', ' ', $description);

        return trim(preg_replace('/\s+/', ' ', $decoded));
    }

    /**
     * The priced lines among a grid of rows.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function readWorkbook(array $rows): array
    {
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

            $description = self::clean((string) $cell('description'));
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
                    'match_key' => self::keyFor($description),
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
     * The totals and rates among a grid of rows.
     *
     * Read by label rather than by cell, because the block shifts down as
     * sections are added: the numbers sit somewhere to the right of a phrase
     * like "TOTAL MATERIAL COST", and the rightmost number on that row is the
     * one that matters.
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function readRecap(array $rows): array
    {
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
}
