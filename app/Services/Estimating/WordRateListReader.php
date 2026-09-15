<?php

namespace App\Services\Estimating;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;
use SplFileInfo;

/**
 * Reads a vendor rate list out of a Word document — .docx, the legacy binary
 * .doc, RTF, or an OpenDocument text file — for a vendor who quotes in a
 * document rather than a workbook.
 *
 * A Word document carries real tables, unlike a PDF, so nothing here guesses
 * at column breaks: every table anywhere in the document is read cell by
 * cell and handed straight to {@see WorkbookReader::fromGrids()}, which
 * already knows how to find a header row by its own column names and hunt a
 * recap total down by its label wherever it happens to sit.
 */
class WordRateListReader
{
    /** Which PhpWord reader opens which extension. */
    private const READERS = [
        'docx' => 'Word2007',
        'doc' => 'MsDoc',
        'rtf' => 'RTF',
        'odt' => 'ODText',
    ];

    public function __construct(private readonly WorkbookReader $workbook) {}

    /** @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>} */
    public function parse(SplFileInfo $file, string $extension): array
    {
        $readerName = self::READERS[mb_strtolower($extension)] ?? null;

        if ($readerName === null) {
            throw new RuntimeException("Don't know how to read a \".{$extension}\" file as a rate list.");
        }

        $rows = $this->rows($file, $readerName);

        // Every row goes to both passes: an item row never carries a recap
        // label, and a recap row never carries a quantity and a unit
        // together, so nothing here needs to sort tables into "the estimate"
        // and "the recap" — the same grid answers both questions.
        return $this->workbook->fromGrids($rows, $rows);
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(SplFileInfo $file, string $readerName): array
    {
        $document = IOFactory::load($file->getPathname(), $readerName);

        $rows = [];

        foreach ($document->getSections() as $section) {
            $this->collectRows($section, $rows);
        }

        return $rows;
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function collectRows(AbstractContainer $container, array &$rows): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    $rows[] = array_map(
                        fn (Cell $cell) => $this->cellText($cell),
                        $row->getCells(),
                    );
                }

                continue;
            }

            // Tables can sit inside another container (a text box, a nested
            // section) rather than directly in the section itself.
            if ($element instanceof AbstractContainer) {
                $this->collectRows($element, $rows);
            }
        }
    }

    /** Every run of text a cell holds, joined — a cell is a small document of its own. */
    private function cellText(Cell $cell): string
    {
        $text = '';

        foreach ($cell->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $text .= $element->getText().' ';
            }
        }

        return WorkbookReader::clean($text);
    }
}
