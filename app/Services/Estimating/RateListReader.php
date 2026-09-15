<?php

namespace App\Services\Estimating;

use RuntimeException;
use SplFileInfo;

/**
 * Reads a vendor rate list regardless of what format the vendor sent it in.
 *
 * An estimator does not get to choose what a vendor emails them — an .xlsx
 * workbook one day, a PDF quote sheet the next, a Word document from a
 * vendor who typed it up by hand. This is the one door in: it looks at the
 * file's own name to say which of the format-specific readers actually
 * understands it, and every one of them ends up producing the exact same
 * shape — {@see WorkbookReader::fromGrids()} is the shared engine behind
 * every format, so a symbol matches an item the same way no matter which
 * kind of file it was quoted in.
 */
class RateListReader
{
    /** Which reader handles which extension. */
    private const SPREADSHEET = ['xlsx'];

    private const PDF = ['pdf'];

    private const WORD = ['docx', 'doc', 'rtf', 'odt'];

    public function __construct(
        private readonly WorkbookReader $workbook,
        private readonly PdfRateListReader $pdf,
        private readonly WordRateListReader $word,
    ) {}

    /**
     * @param  string  $fileName  The file's own name — its extension is
     *     what says which format this is, not whatever the upload was
     *     temporarily stored under.
     * @return array{lines: list<array<string, mixed>>, recap: array<string, mixed>}
     */
    public function parse(SplFileInfo $file, string $fileName): array
    {
        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, self::SPREADSHEET, true) => $this->workbook->parse($file),
            in_array($extension, self::PDF, true) => $this->pdf->parse($file),
            in_array($extension, self::WORD, true) => $this->word->parse($file, $extension),
            default => throw new RuntimeException(
                "\"{$fileName}\" isn't a format this can read as a rate list."
            ),
        };
    }
}
