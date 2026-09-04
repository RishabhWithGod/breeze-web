<?php

namespace App\Services\Export;

use App\Models\Invoice;
use setasign\Fpdi\Fpdi;

/**
 * Renders an invoice as a client-ready PDF: header, line items, then the
 * subtotal / tax / total / paid / balance-due block — the same shape
 * `EstimatePdfWriter` already renders an estimate in.
 */
class InvoicePdfWriter
{
    /** @return string Raw PDF bytes. */
    public function render(Invoice $invoice): string
    {
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetTitle("Invoice {$invoice->invoice_number}");
        $pdf->AddPage('P', 'A4');

        $this->header($pdf, $invoice);
        $this->itemsTable($pdf, $invoice);
        $this->totals($pdf, $invoice);

        return $pdf->Output('S');
    }

    private function header(Fpdi $pdf, Invoice $invoice): void
    {
        $pdf->SetFont('Helvetica', 'B', 17);
        $pdf->Cell(0, 9, $this->ascii("Invoice {$invoice->invoice_number}"), 0, 1);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);

        foreach ([
            'Client' => $invoice->client,
            'Job' => $invoice->job?->name ?? '-',
            'Invoice date' => $invoice->invoice_date->format('m/d/Y'),
            'Due date' => $invoice->due_date?->format('m/d/Y') ?? '-',
            'Status' => str($invoice->displayStatus())->headline()->value(),
        ] as $label => $value) {
            $pdf->Cell(26, 5, $this->ascii($label), 0, 0);
            $pdf->Cell(0, 5, $this->ascii((string) $value), 0, 1);
        }

        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    private function itemsTable(Fpdi $pdf, Invoice $invoice): void
    {
        $items = $invoice->items;

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetFillColor(238, 238, 238);
        foreach ([['Description', 118], ['Qty', 20], ['Unit price', 24], ['Total', 30]] as [$heading, $width]) {
            $pdf->Cell($width, 5.5, $this->ascii($heading), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 8);

        foreach ($items as $item) {
            $pdf->Cell(118, 5, $this->ascii(str($item->description)->limit(70, '')->value()), 1);
            $pdf->Cell(20, 5, rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.'), 1, 0, 'R');
            $pdf->Cell(24, 5, number_format((float) $item->unit_price, 2), 1, 0, 'R');
            $pdf->Cell(30, 5, number_format((float) $item->total, 2), 1, 1, 'R');
        }

        $pdf->Ln(3);
    }

    private function totals(Fpdi $pdf, Invoice $invoice): void
    {
        $rows = [
            ['Subtotal', (float) $invoice->subtotal, false],
            ["Tax ({$invoice->tax_pct}%)", (float) $invoice->tax_total, false],
            ['Total', (float) $invoice->total, true],
            ['Paid', (float) $invoice->paid_amount, false],
            ['Balance due', $invoice->outstanding(), true],
        ];

        foreach ($rows as [$label, $value, $bold]) {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 10 : 9);
            $pdf->Cell(122);
            $pdf->Cell(30, 6, $this->ascii($label), 0, 0, 'R');
            $pdf->Cell(30, 6, number_format($value, 2), 0, 1, 'R');
        }

        if (filled($invoice->notes)) {
            $pdf->Ln(4);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->MultiCell(0, 4.5, $this->ascii($invoice->notes));
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
