<?php

namespace Tests\Feature;

use App\Models\ProjectRateItem;
use App\Models\User;
use App\Services\Estimating\ProjectRateBookImporter;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * A vendor sends a rate list in whatever format they use — an Excel export
 * is only ever one of them. These tests hold that a PDF quote sheet and a
 * Word document price an estimate exactly the way an .xlsx workbook already
 * does, via the same {@see \App\Services\Estimating\RateListReader} door
 * every format goes through.
 */
class RateListDocumentFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pdf_quote_sheet_is_read_into_the_project_rate_book(): void
    {
        $project = User::factory()->create()->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);

        $path = $this->buildPdf();

        app(ProjectRateBookImporter::class)->importWorkbook(
            new \SplFileInfo($path),
            'vendor-quote.pdf',
            $project->id,
        );

        $item = ProjectRateItem::where('project_id', $project->id)
            ->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')
            ->sole();

        $this->assertSame('60.0000', $item->unit_material_cost);
        $this->assertSame('1.250000', $item->unit_manhours);
        $this->assertSame('ea', mb_strtolower($item->unit));

        $conduit = ProjectRateItem::where('project_id', $project->id)
            ->where('description', '3/4" CONDUIT - EMT')
            ->sole();

        $this->assertSame('0.8296', $conduit->unit_material_cost);
    }

    public function test_a_word_document_is_read_into_the_project_rate_book(): void
    {
        $project = User::factory()->create()->projects()->create([
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'completed',
        ]);

        $path = $this->buildDocx();

        app(ProjectRateBookImporter::class)->importWorkbook(
            new \SplFileInfo($path),
            'vendor-rates.docx',
            $project->id,
        );

        $item = ProjectRateItem::where('project_id', $project->id)
            ->where('description', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE')
            ->sole();

        $this->assertSame('60.0000', $item->unit_material_cost);
        $this->assertSame('1.250000', $item->unit_manhours);
    }

    /**
     * A quote sheet exported straight out of a spreadsheet: real text runs
     * with real space characters between columns, the way Excel's own "Save
     * as PDF" lays a page out — not bordered cells with no padding, which is
     * a much harder case nothing here promises to solve.
     */
    private function buildPdf(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Courier', '', 10);

        $widths = [10, 46, 12, 8, 24, 16, 16];
        $pad = fn (array $values) => implode('', array_map(
            fn ($value, $width) => str_pad((string) $value, $width),
            $values,
            $widths,
        ));

        $y = 10;
        $pdf->Text(10, $y, $pad(['SR. NO.', 'DESCRIPTION', 'QUANTITY', 'UNIT', 'UNIT MATERIAL COST', 'MANHOUR RATE', 'UNIT MANHOURS']));
        $y += 6;
        $pdf->Text(10, $y, $pad(['1', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE', '10', 'EA', '60.00', '48', '1.25']));
        $y += 6;
        $pdf->Text(10, $y, $pad(['2', '3/4" CONDUIT - EMT', '250', 'FT', '0.8296', '48', '0.062']));
        $y += 12;
        $pdf->Text(10, $y, 'OVERHEADS @ 0.1        1000.00');
        $y += 6;
        $pdf->Text(10, $y, 'PROFIT @ 0.12        1200.00');
        $y += 6;
        $pdf->Text(10, $y, 'MATERIAL SALES TAX 0.075        75.00');

        $path = tempnam(sys_get_temp_dir(), 'ratelist').'.pdf';
        $pdf->Output('F', $path);

        return $path;
    }

    private function buildDocx(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();

        $table = $section->addTable();
        $table->addRow();
        foreach (['SR. NO.', 'DESCRIPTION', 'QUANTITY', 'UNIT', 'UNIT MATERIAL COST', 'MANHOUR RATE', 'UNIT MANHOURS'] as $header) {
            $table->addCell(2000)->addText($header);
        }

        foreach ([
            ['1', 'EM2, NEW BATTERY 2/HEAD EM FIXTURE', '10', 'EA', '60.00', '48', '1.25'],
            ['2', '3/4" CONDUIT - EMT', '250', 'FT', '0.8296', '48', '0.062'],
        ] as $row) {
            $table->addRow();
            foreach ($row as $value) {
                $table->addCell(2000)->addText($value);
            }
        }

        $recap = $section->addTable();
        foreach ([
            ['OVERHEADS @', '0.1', '1000.00'],
            ['PROFIT @', '0.12', '1200.00'],
            ['MATERIAL SALES TAX', '0.075', '75.00'],
        ] as $row) {
            $recap->addRow();
            foreach ($row as $value) {
                $recap->addCell(2000)->addText($value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ratelist').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }
}
