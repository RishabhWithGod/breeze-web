<?php

namespace App\Services\Export;

use App\Models\AiResult;
use App\Models\FinalSymbol;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exports the final symbol table.
 *
 * Every format is generated from the same rows the table renders, so an export
 * always matches what the reviewer signed off.
 */
class SymbolExporter
{
    private const HEADINGS = [
        'Symbol', 'Count', 'Template', 'Vector', 'Vision', 'OCR', 'Sources', 'Confidence', 'Pages',
    ];

    /** @param  Collection<int, FinalSymbol>  $symbols */
    public function csv(Collection $symbols): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADINGS);

        foreach ($this->rows($symbols) as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Writes a real .xlsx workbook to a temporary file and returns its path; the
     * caller streams it and deletes it.
     *
     * @param  Collection<int, FinalSymbol>  $symbols
     */
    public function xlsx(Collection $symbols, string $sheetName = 'Final Symbols'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'symbols_').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName(str($sheetName)->limit(28, '')->value());

        $header = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(self::HEADINGS, $header));

        foreach ($this->rows($symbols) as $row) {
            $writer->addRow(Row::fromValues($row));
        }

        $writer->close();

        return $path;
    }

    /**
     * The reviewed document itself. Falls back to composing an equivalent payload
     * when the stored file predates a later rebuild.
     *
     * @return array<string, mixed>
     */
    public function json(AiResult $result): array
    {
        return $result->final_payload ?? [
            'ai_result_id' => $result->id,
            'project_id' => $result->project_id,
            'final_counts' => $result->finalSymbols()
                ->get()
                ->mapWithKeys(fn (FinalSymbol $symbol) => [$symbol->name => $symbol->count])
                ->all(),
            'note' => 'This takeoff has not been finalised yet, so no reviewed document exists.',
        ];
    }

    /**
     * @param  Collection<int, FinalSymbol>  $symbols
     * @return list<list<string|int>>
     */
    private function rows(Collection $symbols): array
    {
        return $symbols->map(fn (FinalSymbol $symbol) => [
            $symbol->name,
            $symbol->count,
            $symbol->source_template ? 'Yes' : '',
            $symbol->source_vector ? 'Yes' : '',
            $symbol->source_vision ? 'Yes' : '',
            $symbol->source_ocr ? 'Yes' : '',
            implode(', ', $symbol->sourceLabels()),
            round($symbol->confidence * 100).'%',
            implode(', ', $symbol->pages ?? []),
        ])->values()->all();
    }
}
