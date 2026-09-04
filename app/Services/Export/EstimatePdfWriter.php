<?php

namespace App\Services\Export;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\Ai\ArtefactStore;
use Illuminate\Support\Collection;
use setasign\Fpdi\Fpdi;

/**
 * Renders an estimate as a client-ready PDF: header, one table per category, then
 * the subtotal / markup / tax / grand-total block.
 */
class EstimatePdfWriter
{
    public function __construct(private readonly ArtefactStore $store) {}

    /** @return string Raw PDF bytes. */
    public function render(Estimate $estimate): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetTitle("Estimate {$estimate->number}");
        $pdf->AddPage('P', 'A4');

        $this->header($pdf, $estimate);

        foreach (EstimateItem::CATEGORIES as $category) {
            $items = $estimate->items()->where('category', $category)->orderBy('position')->get();

            if ($items->isEmpty()) {
                continue;
            }

            $this->categoryTable($pdf, EstimateItem::CATEGORY_LABELS[$category], $items);
        }

        $this->totals($pdf, $estimate);

        return $pdf->Output('S');
    }

    /** Renders and files the PDF next to the takeoff's other artefacts. */
    public function writeToDisk(Estimate $estimate): ?string
    {
        $project = $estimate->takeoffProject;

        if (! $project) {
            return null;
        }

        return $this->store->put($project, "estimate-{$estimate->number}.pdf", $this->render($estimate));
    }

    private function header(Fpdi $pdf, Estimate $estimate): void
    {
        $pdf->SetFont('Helvetica', 'B', 17);
        $pdf->Cell(0, 9, $this->ascii("Estimate {$estimate->number}"), 0, 1);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);

        foreach ([
            'Client' => $estimate->client,
            'Project' => $estimate->project,
            'Issued' => $estimate->issued_on?->format('m/d/Y'),
            'Status' => str($estimate->status)->headline()->value(),
            'Source' => $estimate->ai_result_id ? 'Reviewed AI takeoff' : 'Manual',
        ] as $label => $value) {
            $pdf->Cell(26, 5, $this->ascii($label), 0, 0);
            $pdf->Cell(0, 5, $this->ascii((string) $value), 0, 1);
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    /** @param  Collection<int, EstimateItem>  $items */
    private function categoryTable(Fpdi $pdf, string $label, $items): void
    {
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 7, $this->ascii($label), 0, 1);

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(238, 238, 238);
        foreach ([['Description', 96], ['Unit', 16], ['Qty', 20], ['Unit cost', 24], ['Total', 26]] as [$heading, $width]) {
            $pdf->Cell($width, 5.5, $this->ascii($heading), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 8);

        foreach ($items as $item) {
            $pdf->Cell(96, 5, $this->ascii(str($item->description)->limit(58, '')->value()), 1);
            $pdf->Cell(16, 5, $this->ascii($item->unit), 1);
            $pdf->Cell(20, 5, rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.'), 1, 0, 'R');
            $pdf->Cell(24, 5, number_format((float) $item->unit_cost, 2), 1, 0, 'R');
            $pdf->Cell(26, 5, number_format((float) $item->total, 2), 1, 1, 'R');
        }

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell(156, 5, $this->ascii("{$label} subtotal"), 1);
        $pdf->Cell(26, 5, number_format((float) $items->sum('total'), 2), 1, 1, 'R');
        $pdf->Ln(3);
    }

    private function totals(Fpdi $pdf, Estimate $estimate): void
    {
        $pdf->Ln(2);

        $rows = [
            ['Materials and fixtures', (float) $estimate->material_total, false],
            ['Labor', (float) $estimate->labor_total, false],
            ['Equipment', (float) $estimate->equipment_total, false],
            ['Subtotal', (float) $estimate->subtotal, true],
            ["Markup ({$estimate->markup_pct}%)", (float) $estimate->markup_total, false],
            ["Tax ({$estimate->tax_pct}%)", (float) $estimate->tax_total, false],
            ['Grand total', (float) $estimate->grand_total, true],
        ];

        foreach ($rows as [$label, $value, $bold]) {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 10 : 9);
            $pdf->Cell(120);
            $pdf->Cell(36, 6, $this->ascii($label), 0, 0, 'R');
            $pdf->Cell(26, 6, number_format($value, 2), 0, 1, 'R');
        }

        if (filled($estimate->notes)) {
            $pdf->Ln(4);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->MultiCell(0, 4.5, $this->ascii($estimate->notes));
        }
    }

    private function ascii(string $value): string
    {
        return str($value)
            ->replace(['—', '–', '“', '”', '’', '‘', '×'], ['-', '-', '"', '"', "'", "'", 'x'])
            ->ascii()
            ->value();
    }
}
