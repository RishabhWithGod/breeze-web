<?php

namespace App\Services\Ai;

use App\Models\SymbolReview;
use App\Models\Upload;
use Illuminate\Support\Facades\Log;

/**
 * Cuts each detection's bounding box out of the rendered page so the review
 * cards show the actual symbol rather than a placeholder.
 *
 * The AI reports boxes in its own page pixel space; previews are rendered at a
 * different DPI, so boxes are scaled by the ratio between the two.
 */
class CropRenderer
{
    /** Padding around the box, as a fraction of its longest side. */
    private const PADDING_RATIO = 0.18;

    public function __construct(private readonly ArtefactStore $store) {}

    public function available(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * @param  array<int, array{width: float, height: float}>  $pageSizes  Keyed by page number.
     * @return int Number of crops written.
     */
    public function renderFor(Upload $upload, iterable $reviews, array $pageSizes): int
    {
        if (! $this->available()) {
            return 0;
        }

        $sheets = [];
        $written = 0;

        foreach ($reviews as $review) {
            $bbox = $review->bbox;

            if (! is_array($bbox) || count($bbox) < 4) {
                continue;
            }

            $preview = $upload->previewFor($review->page);

            if (! $this->store->exists($preview)) {
                continue;
            }

            $sheets[$review->page] ??= @imagecreatefrompng($this->store->absolutePath($preview));
            $sheet = $sheets[$review->page];

            if (! $sheet) {
                continue;
            }

            $path = $this->crop($upload, $review, $sheet, $pageSizes[$review->page] ?? null);

            if ($path !== null) {
                $review->update(['crop_path' => $path]);
                $written++;
            }
        }

        foreach (array_filter($sheets) as $sheet) {
            imagedestroy($sheet);
        }

        return $written;
    }

    /** @param  array{width: float, height: float}|null  $pageSize */
    private function crop(Upload $upload, SymbolReview $review, \GdImage $sheet, ?array $pageSize): ?string
    {
        [$x, $y, $width, $height] = array_map('floatval', array_slice($review->bbox, 0, 4));

        $sheetWidth = imagesx($sheet);
        $sheetHeight = imagesy($sheet);

        // Scale from the AI's page space into preview pixels. Without declared
        // page dimensions the box is assumed to already be in preview space.
        $scaleX = ($pageSize && $pageSize['width'] > 0) ? $sheetWidth / $pageSize['width'] : 1.0;
        $scaleY = ($pageSize && $pageSize['height'] > 0) ? $sheetHeight / $pageSize['height'] : 1.0;

        $pad = max($width, $height) * self::PADDING_RATIO;

        $left = (int) max(0, round(($x - $pad) * $scaleX));
        $top = (int) max(0, round(($y - $pad) * $scaleY));
        $cropWidth = (int) min($sheetWidth - $left, max(12, round(($width + $pad * 2) * $scaleX)));
        $cropHeight = (int) min($sheetHeight - $top, max(12, round(($height + $pad * 2) * $scaleY)));

        if ($cropWidth < 4 || $cropHeight < 4) {
            return null;
        }

        $crop = imagecrop($sheet, ['x' => $left, 'y' => $top, 'width' => $cropWidth, 'height' => $cropHeight]);

        if (! $crop) {
            return null;
        }

        $name = 'crops/'.str($review->external_id ?: 'crop-'.$review->id)->slug().'.png';

        try {
            ob_start();
            imagepng($crop, null, 6);
            $contents = (string) ob_get_clean();

            return $this->store->put($upload->project, $name, $contents);
        } catch (\Throwable $e) {
            Log::warning('Symbol crop could not be written', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            imagedestroy($crop);
        }
    }
}
