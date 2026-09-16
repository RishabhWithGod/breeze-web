<?php

namespace App\Services\Estimating;

use Smalot\PdfParser\Parser;
use SplFileInfo;

/**
 * Reads a vendor rate list out of a PDF — a vendor who quotes on a printed
 * sheet rather than handing out a workbook.
 *
 * A PDF has no cells, only text positioned on a page. The parser already
 * turns that into lines in reading order; two or more spaces (or a tab)
 * between two runs of text is read as the column break that a spreadsheet
 * would have drawn a grid line at, which turns a printed table back into the
 * same row-of-cells shape {@see WorkbookReader::fromGrids()} already reads —
 * the header names, the "TOTAL MATERIAL COST" label hunt, all of it apply
 * completely unchanged once the page has been split this way.
 *
 * The same lines are handed to both the item pass and the recap pass: an
 * item row never carries a recap label, and a recap row never carries a
 * quantity and a unit together, so nothing here needs to know which page of
 * the PDF the totals happened to print on.
 */
class PdfRateListReader
{
    public function __construct(private readonly WorkbookReader $workbook) {}

    /** @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>} */
    public function parse(SplFileInfo $file): array
    {
        $rows = $this->rows($file);

        return $this->workbook->fromGrids($rows, $rows);
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(SplFileInfo $file): array
    {
        $text = (new Parser)->parseFile($file->getPathname())->getText();

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) as $number => $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            $rows[$number] = preg_split('/\t+| {2,}/', $line);
        }

        return $rows;
    }
}
