<?php

namespace App\Services\Export;

use App\Models\AiResult;
use App\Models\SymbolReview;
use App\Services\Ai\ArtefactStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Draws the reviewed detections back onto the drawing.
 *
 * The original PDF pages are imported and stamped with each bounding box, its
 * symbol name and reviewed count — approved in green, rejected in red, still
 * pending in amber — followed by a summary page.
 *
 * Two ways in: the original PDF (preferred), or the rendered page previews when
 * the PDF cannot be imported. Either way the geometry is the AI's, scaled from
 * its page space into the page being drawn.
 */
class AnnotatedPdfWriter
{
    /** RGB per review status. */
    private const COLOURS = [
        SymbolReview::STATUS_APPROVED => [34, 197, 94],
        SymbolReview::STATUS_REJECTED => [239, 68, 68],
        SymbolReview::STATUS_PENDING => [245, 158, 11],
    ];

    public function __construct(private readonly ArtefactStore $store) {}

    /** @return string Path on the artefact disk. */
    public function write(AiResult $result): string
    {
        $reviews = $result->reviews()->get();
        $pageSizes = $this->pageSizes($result);

        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle("Annotated takeoff — {$result->project->name}");

        $imported = $this->stampOriginal($pdf, $result, $reviews, $pageSizes)
            || $this->stampPreviews($pdf, $result, $reviews, $pageSizes);

        if (! $imported) {
            // No drawing to draw on: still produce boxes on blank sheets so the
            // geometry and counts are inspectable.
            $this->stampBlank($pdf, $result, $reviews, $pageSizes);
        }

        $this->summaryPage($pdf, $result, $reviews);

        $path = $this->store->put($result->project, 'annotated.pdf', $pdf->Output('S'));

        $result->upload?->update(['annotated_path' => $path]);

        return $path;
    }

    /* ----------------------------------------------------------- strategies */

    /**
     * Imports the original PDF. `qpdf` first decompresses object streams, which
     * the bundled parser cannot read.
     *
     * @param  Collection<int, SymbolReview>  $reviews
     * @param  array<int, array{width: float, height: float}>  $pageSizes
     */
    private function stampOriginal(Fpdi $pdf, AiResult $result, Collection $reviews, array $pageSizes): bool
    {
        $upload = $result->upload;

        if (! $upload || $upload->format !== 'PDF' || ! $this->store->exists($upload->path)) {
            return false;
        }

        $source = $this->store->absolutePath($upload->path);
        $decompressed = tempnam(sys_get_temp_dir(), 'annot_').'.pdf';

        // `qpdf` uncompressing is an optimization for FPDI's parser, not a
        // requirement — a missing binary throws before `run()` even
        // returns a result to check `->successful()` on, so that has to
        // be caught here too, exactly like a non-zero exit: fall back to
        // the untouched source and let FPDI's own import attempt (already
        // wrapped below) decide whether it can be read at all.
        try {
            $qpdf = Process::timeout(20)->run([
                'qpdf', '--stream-data=uncompress', '--object-streams=disable', $source, $decompressed,
            ]);
            $qpdfSucceeded = $qpdf->successful();
        } catch (Throwable $e) {
            Log::info('qpdf is unavailable; annotating the original PDF without pre-decompression', [
                'ai_result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
            $qpdfSucceeded = false;
        }

        $candidate = ($qpdfSucceeded && filesize($decompressed) > 0) ? $decompressed : $source;

        try {
            $pageCount = $pdf->setSourceFile($candidate);

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);

                $this->drawBoxes(
                    $pdf,
                    $reviews->where('page', $page),
                    $pageSizes[$page] ?? null,
                    $size['width'],
                    $size['height'],
                );
                $this->pageLabel($pdf, $result, $page, $pageCount);
            }

            return $pageCount > 0;
        } catch (Throwable $e) {
            Log::info('Original PDF could not be imported for annotation; falling back to previews', [
                'ai_result_id' => $result->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            @unlink($decompressed);
        }
    }

    /**
     * @param  Collection<int, SymbolReview>  $reviews
     * @param  array<int, array{width: float, height: float}>  $pageSizes
     */
    private function stampPreviews(Fpdi $pdf, AiResult $result, Collection $reviews, array $pageSizes): bool
    {
        $previews = $result->upload?->preview_paths ?? [];

        if ($previews === []) {
            return false;
        }

        foreach ($previews as $index => $preview) {
            if (! $this->store->exists($preview)) {
                continue;
            }

            $page = $index + 1;
            $absolute = $this->store->absolutePath($preview);
            [$pixelWidth, $pixelHeight] = getimagesize($absolute) ?: [1000, 1400];

            // Fit the render to A4 while preserving its aspect ratio.
            $width = 210.0;
            $height = round($width * ($pixelHeight / max(1, $pixelWidth)), 2);

            $pdf->AddPage($height > $width ? 'P' : 'L', [$width, $height]);
            $pdf->Image($absolute, 0, 0, $width, $height, 'PNG');

            $this->drawBoxes($pdf, $reviews->where('page', $page), $pageSizes[$page] ?? null, $width, $height);
            $this->pageLabel($pdf, $result, $page, count($previews));
        }

        return $pdf->PageNo() > 0;
    }

    /**
     * @param  Collection<int, SymbolReview>  $reviews
     * @param  array<int, array{width: float, height: float}>  $pageSizes
     */
    private function stampBlank(Fpdi $pdf, AiResult $result, Collection $reviews, array $pageSizes): void
    {
        foreach ($reviews->groupBy('page') as $page => $pageReviews) {
            $pdf->AddPage('L', 'A4');
            $pdf->SetFont('Helvetica', '', 8);
            $this->drawBoxes($pdf, $pageReviews, $pageSizes[$page] ?? null, 297.0, 210.0);
            $this->pageLabel($pdf, $result, (int) $page, $reviews->pluck('page')->unique()->count());
        }
    }

    /* ---------------------------------------------------------------- drawing */

    /**
     * @param  Collection<int, SymbolReview>  $reviews
     * @param  array{width: float, height: float}|null  $pageSize
     */
    private function drawBoxes(Fpdi $pdf, Collection $reviews, ?array $pageSize, float $width, float $height): void
    {
        $scaleX = ($pageSize && $pageSize['width'] > 0) ? $width / $pageSize['width'] : null;
        $scaleY = ($pageSize && $pageSize['height'] > 0) ? $height / $pageSize['height'] : null;

        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetLineWidth(0.35);

        foreach ($reviews as $review) {
            $bbox = $review->bbox;

            if (! is_array($bbox) || count($bbox) < 4) {
                continue;
            }

            // Without declared page dimensions the boxes cannot be placed
            // faithfully, so they are laid out proportionally to the sheet.
            [$x, $y, $boxWidth, $boxHeight] = array_map('floatval', array_slice($bbox, 0, 4));
            $left = round($x * ($scaleX ?? ($width / 3000)), 2);
            $top = round($y * ($scaleY ?? ($height / 2200)), 2);
            $boxWidth = max(2.0, round($boxWidth * ($scaleX ?? ($width / 3000)), 2));
            $boxHeight = max(2.0, round($boxHeight * ($scaleY ?? ($height / 2200)), 2));

            if ($left > $width || $top > $height) {
                continue;
            }

            [$r, $g, $b] = self::COLOURS[$review->status] ?? self::COLOURS[SymbolReview::STATUS_PENDING];
            $pdf->SetDrawColor($r, $g, $b);
            $pdf->Rect($left, $top, $boxWidth, $boxHeight);

            $label = $this->label($review);
            $labelWidth = $pdf->GetStringWidth($label) + 1.6;
            $labelTop = max(0.0, $top - 3.2);

            $pdf->SetFillColor($r, $g, $b);
            $pdf->Rect($left, $labelTop, $labelWidth, 3.0, 'F');
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($left + 0.8, $labelTop + 0.2);
            $pdf->Cell($labelWidth, 2.6, $label, 0, 0, 'L');
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    private function label(SymbolReview $review): string
    {
        $name = str($review->name)->limit(28, '')->value();
        $count = $review->final_count > 1 ? " x{$review->final_count}" : '';
        $mark = match ($review->status) {
            SymbolReview::STATUS_APPROVED => '',
            SymbolReview::STATUS_REJECTED => ' (rejected)',
            default => ' (pending)',
        };

        return $this->ascii($name.$count.$mark);
    }

    private function pageLabel(Fpdi $pdf, AiResult $result, int $page, int $total): void
    {
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY(4, 4);
        $pdf->Cell(
            120,
            4,
            $this->ascii("{$result->project->name} — annotated page {$page} of {$total}"),
            0,
            0,
            'L',
        );
        $pdf->SetTextColor(0, 0, 0);
    }

    /** @param  Collection<int, SymbolReview>  $reviews */
    private function summaryPage(Fpdi $pdf, AiResult $result, Collection $reviews): void
    {
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('Helvetica', 'B', 15);
        $pdf->Cell(0, 9, $this->ascii('Annotated takeoff summary'), 0, 1);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        foreach ([
            'Project' => $result->project->name,
            'Drawing' => $result->project->drawing_name,
            'Model' => $result->model_version ?? 'n/a',
            'Generated' => now()->format('j M Y, H:i'),
        ] as $label => $value) {
            $pdf->Cell(30, 5, $this->ascii($label), 0, 0);
            $pdf->Cell(0, 5, $this->ascii((string) $value), 0, 1);
        }

        $pdf->Ln(3);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        foreach ([['Symbol', 86], ['Approved', 26], ['Rejected', 26], ['Pending', 26], ['Final count', 26]] as [$heading, $width]) {
            $pdf->Cell($width, 6, $this->ascii($heading), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 9);

        foreach ($reviews->groupBy('name')->sortKeys() as $name => $group) {
            $pdf->Cell(86, 5.5, $this->ascii(str($name)->limit(48, '')->value()), 1);
            $pdf->Cell(26, 5.5, (string) $group->where('status', SymbolReview::STATUS_APPROVED)->count(), 1, 0, 'R');
            $pdf->Cell(26, 5.5, (string) $group->where('status', SymbolReview::STATUS_REJECTED)->count(), 1, 0, 'R');
            $pdf->Cell(26, 5.5, (string) $group->where('status', SymbolReview::STATUS_PENDING)->count(), 1, 0, 'R');
            $pdf->Cell(
                26,
                5.5,
                (string) $group->filter->countsTowardsFinal()->sum('final_count'),
                1,
                1,
                'R',
            );
        }
    }

    /** FPDF's core fonts are Latin-1, so smart punctuation is folded away. */
    private function ascii(string $value): string
    {
        return str($value)
            ->replace(['—', '–', '“', '”', '’', '‘', '×'], ['-', '-', '"', '"', "'", "'", 'x'])
            ->ascii()
            ->value();
    }

    /**
     * The AI's page dimensions, needed to map boxes onto a printed page.
     *
     * @return array<int, array{width: float, height: float}>
     */
    private function pageSizes(AiResult $result): array
    {
        $pages = $result->original_payload['pages'] ?? [];

        return collect(is_array($pages) ? $pages : [])
            ->mapWithKeys(function ($page, $index) {
                if (! is_array($page)) {
                    return [];
                }

                $number = (int) ($page['number'] ?? $page['page'] ?? $index + 1);

                return [$number => [
                    'width' => (float) ($page['width'] ?? data_get($page, 'size.width', 0)),
                    'height' => (float) ($page['height'] ?? data_get($page, 'size.height', 0)),
                ]];
            })
            ->filter(fn (array $size) => $size['width'] > 0 && $size['height'] > 0)
            ->all();
    }
}
