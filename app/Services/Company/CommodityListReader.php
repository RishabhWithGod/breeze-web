<?php

namespace App\Services\Company;

use App\Models\PriceBookLine;
use App\Services\Estimating\PdfRateListReader;
use App\Services\Estimating\RateListReader;
use App\Services\Estimating\WordRateListReader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Understands a price list in whatever form a company has it — a spreadsheet, a CSV, a PDF, a
 * Word document — and reads it into items: category, code, description, unit, material price
 * and labor hours.
 *
 * Nothing is guessed from a fixed layout. The header row is found by what its columns are
 * called ("Item", "Description", "UOM", "Unit Price", "Man Hours"…), wherever it sits and in
 * whatever order; a bare line of text over a run of rows is read as their category. A file with
 * no header at all (a PDF whose lines are just "Junction box  EA  3.60") is read line by line as
 * a last resort, and a full estimating workbook is read the way the estimator's own reader does.
 * A row is only ever kept when it has a name and a price or hours — nothing is invented.
 */
class CommodityListReader
{
    public const MAX_ITEMS = 2000;

    /** Formats that can be read. */
    public const EXTENSIONS = ['xlsx', 'ods', 'csv', 'txt', 'pdf', 'doc', 'docx', 'rtf', 'odt'];

    public function __construct(
        private readonly PdfRateListReader $pdf,
        private readonly WordRateListReader $word,
        private readonly RateListReader $estimating,
    ) {}

    /**
     * @return list<array{category: string, item_code: ?string, description: string, unit: string, material_price: float, labor_hours: float, markup_pct: ?float}>
     *
     * @throws RuntimeException when the file cannot be opened as its format
     */
    public function read(SplFileInfo $file, string $fileName): array
    {
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException($extension === 'xls'
                ? 'Old .xls files cannot be read. Save it as .xlsx (or .csv) and upload it again.'
                : "\".{$extension}\" is not a format this can read.");
        }

        $grids = match ($extension) {
            'xlsx' => $this->spreadsheet(new XlsxReader, $file),
            'ods' => $this->spreadsheet(new OdsReader, $file),
            'csv', 'txt' => [$this->csv($file)],
            'pdf' => [$this->pdf->rows($file)],
            default => [$this->word->grid($file, $extension)],
        };

        $items = [];
        foreach ($grids as $grid) {
            $items = [...$items, ...$this->fromHeader($grid)];
        }

        // No header row anywhere: a printed list is still read, line by line.
        if ($items === [] && in_array($extension, ['pdf', 'doc', 'docx', 'rtf', 'odt', 'txt'], true)) {
            foreach ($grids as $grid) {
                $items = [...$items, ...$this->fromLines($grid)];
            }
        }

        // A full estimating workbook (or a printed copy of one) has its own reader.
        if ($items === []) {
            $items = $this->fromEstimate($file, $fileName);
        }

        return $this->distinct($items);
    }

    /** A header for the file people fill in by hand. */
    public function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Category', 'Item Code', 'Description', 'Unit', 'Material Price', 'Labor Hours', 'Markup %'], escape: '');
        fputcsv($out, ['Electrical', 'ELE-001', '4" Junction Box', 'EA', '3.60', '0.10', '15'], escape: '');
        fputcsv($out, ['Labor', 'LAB-001', 'Journeyman Electrician', 'HR', '0.00', '1.00', ''], escape: '');
        rewind($out);

        return stream_get_contents($out);
    }

    // ---------------------------------------------------------------------- grids --

    /** @return list<array<int, array<int, string>>> One grid per sheet. */
    private function spreadsheet(ReaderInterface $reader, SplFileInfo $file): array
    {
        $grids = [];
        $reader->open($file->getPathname());

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map($this->cell(...), $row->getCells());
            }
            $grids[] = $rows;
        }
        $reader->close();

        return $grids;
    }

    /** @return array<int, array<int, string>> */
    private function csv(SplFileInfo $file): array
    {
        // Excel in some countries saves with ; or tabs instead of commas.
        $first = (string) fgets(fopen($file->getPathname(), 'r'));
        $delimiter = collect([',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();

        $options = new CsvOptions;
        $options->FIELD_DELIMITER = $delimiter;
        $reader = new CsvReader($options);
        $reader->open($file->getPathname());

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map($this->cell(...), $row->getCells());
            }
        }
        $reader->close();

        return $rows;
    }

    private function cell(Cell $cell): string
    {
        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            default => trim(preg_replace('/\s+/', ' ', str_replace("\u{FEFF}", '', (string) $value))),
        };
    }

    // ---------------------------------------------------------------- with a header --

    /**
     * @param  array<int, array<int, mixed>>  $grid
     * @return list<array<string, mixed>>
     */
    private function fromHeader(array $grid): array
    {
        $rows = array_values($grid);
        $items = [];
        $map = null;
        $heading = null;

        foreach ($rows as $index => $row) {
            $cells = array_map(fn ($value) => trim((string) $value), $row);

            if ($map === null) {
                // The header is somewhere near the top, under whatever title the sheet has.
                if ($index < 40 && ($found = $this->headerMap($cells)) !== null) {
                    $map = $found;
                }

                continue;
            }

            $filled = array_values(array_filter($cells, fn ($c) => $c !== ''));
            if ($filled === []) {
                continue;
            }
            // The header repeated at the top of each printed page.
            if ($this->headerMap($cells) !== null) {
                continue;
            }

            $get = fn (string $role) => isset($map[$role]) ? ($cells[$map[$role]] ?? '') : '';
            $description = mb_substr($get('description'), 0, 255);

            // One bare line over a run of rows names the category they belong to.
            if ($description === '' || (count($filled) === 1 && $this->number($description) === null)) {
                if (count($filled) === 1 && ! preg_match('/^(sub)?total\b/i', $filled[0]) && mb_strlen($filled[0]) <= 80) {
                    $heading = $filled[0];
                }

                continue;
            }

            $price = $this->number($get('price'));
            $hours = $this->number($get('hours'));

            if (preg_match('/^(grand |sub)?total\b/i', $description) || ($price === null && $hours === null)) {
                continue;
            }

            $items[] = $this->item(
                category: $get('category') !== '' ? $get('category') : $heading,
                code: $get('code'),
                description: $description,
                unit: $get('unit'),
                price: $price,
                hours: $hours,
                markup: $this->number($get('markup')),
            );

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Which column holds what, from the header's own names — or null when this row is not a header
     * (it has to name an item and give a price or hours).
     *
     * @param  list<string>  $cells
     * @return array<string, int>|null
     */
    private function headerMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $title) {
            $name = trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($title)));
            if ($name === '' || preg_match('/\b(total|extended|qty|quantity|wastage|amount)\b/', $name)) {
                continue;
            }

            $role = match (true) {
                (bool) preg_match('/\b(markup|mark up|margin)\b/', $name) => 'markup',
                (bool) preg_match('/\b(man ?hours?|labou?r ?hours?|hours?|hrs?|labou?r (per|\/) ?unit)\b/', $name) => 'hours',
                (bool) preg_match('/\b(sku|item (code|no|number|id)|part (no|number|num)|catalog(ue)?( no| number)?|code|model)\b/', $name) => 'code',
                (bool) preg_match('/^(unit|units|uom|u m|unit of measure|measure)$/', $name) => 'unit',
                (bool) preg_match('/^(category|section|group|type|class|division|commodity|trade|item type)$/', $name) => 'category',
                ! preg_match('/\b(labou?r|man)\b/', $name) && preg_match('/\b(price|cost|rate)\b/', $name) => 'price',
                (bool) preg_match('/^(description|item description|item name|item|items|name|product|material|materials|desc|article|commodity description|particulars)$|description/', $name) => 'description',
                default => null,
            };

            if ($role !== null && ! isset($map[$role])) {
                $map[$role] = $index;
            }
        }

        return isset($map['description']) && (isset($map['price']) || isset($map['hours'])) ? $map : null;
    }

    // ------------------------------------------------------------- without a header --

    /**
     * Reads "Junction box   EA   3.60   0.10" lines: a name, an optional unit, a price, optional hours.
     *
     * @param  array<int, array<int, mixed>>  $grid
     * @return list<array<string, mixed>>
     */
    private function fromLines(array $grid): array
    {
        $items = [];
        $heading = null;

        foreach ($grid as $row) {
            $line = trim(preg_replace('/\s+/', ' ', implode(' ', array_map(fn ($v) => (string) $v, $row))));

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?<desc>.*[A-Za-z].*?) (?<unit>[A-Za-z]{1,4})\.? \$?(?<price>\d[\d,]*(?:\.\d+)?)(?: (?<hours>\d+(?:\.\d+)?))?$/', $line, $m)
                && ! preg_match('/^(grand |sub)?total\b/i', $m['desc'])) {
                $items[] = $this->item($heading, '', $m['desc'], $m['unit'], $this->number($m['price']), isset($m['hours']) ? $this->number($m['hours']) : null, null);
            } elseif (preg_match('/^[A-Z][A-Z0-9 &\/,\-]{2,60}$/', $line)) {
                $heading = ucwords(mb_strtolower($line));
            }
        }

        return $items;
    }

    /**
     * An estimating workbook (or a printed copy): its own reader knows the layout.
     *
     * @return list<array<string, mixed>>
     */
    private function fromEstimate(SplFileInfo $file, string $fileName): array
    {
        try {
            ['lines' => $lines] = $this->estimating->parse($file, $fileName);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_map(fn (array $line) => $line['unit_material_cost'] === null && $line['unit_manhours'] === null
            ? null
            : $this->item($line['section'] ?? null, '', $line['description'], $line['unit'] ?? '', $line['unit_material_cost'], $line['unit_manhours'], null), $lines)));
    }

    // ------------------------------------------------------------------- helpers --

    /** @return array{category: string, item_code: ?string, description: string, unit: string, material_price: float, labor_hours: float, markup_pct: ?float} */
    private function item(?string $category, string $code, string $description, string $unit, ?float $price, ?float $hours, ?float $markup): array
    {
        $unit = mb_strtoupper(trim($unit, " .\t"));

        return [
            'category' => mb_substr(trim((string) $category) !== '' ? trim((string) $category) : 'General', 0, 120),
            'item_code' => $code !== '' ? mb_substr($code, 0, 40) : null,
            'description' => mb_substr(trim($description), 0, 255),
            'unit' => $unit !== '' ? mb_substr($unit, 0, 24) : 'EA',
            'material_price' => round(max(0.0, $price ?? 0.0), 4),
            'labor_hours' => round(max(0.0, $hours ?? 0.0), 6),
            'markup_pct' => $markup === null ? null : round(max(0.0, $markup), 2),
        ];
    }

    /** @param  list<array<string, mixed>>  $items  @return list<array<string, mixed>> The same item twice is one item, the last say winning. */
    private function distinct(array $items): array
    {
        $byKey = [];
        foreach ($items as $item) {
            $byKey[PriceBookLine::keyFor($item['description']).'|'.$item['unit']] = $item;
        }

        return array_slice(array_values($byKey), 0, self::MAX_ITEMS);
    }

    /** "$1,250.00", "3.6", "12%" → a number; anything else (a word, a blank) → null. */
    private function number(mixed $value): ?float
    {
        $text = trim(str_replace(['$', ',', '%', ' '], '', (string) $value));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }
}
