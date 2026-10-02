<?php

namespace App\Services\StaticTakeoff;

use App\Models\StaticTakeoffDataset;
use App\Models\Upload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Matches an uploaded drawing to a pre-stored static takeoff dataset by the
 * file's own content hash — stable across renames, immune to path changes.
 */
class StaticTakeoffResolver
{
    public function findByFile(string $absolutePath): ?StaticTakeoffDataset
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $hash = hash_file('sha256', $absolutePath);

        if ($hash === false) {
            return null;
        }

        return $this->findByHash($hash);
    }

    public function findByHash(string $hash): ?StaticTakeoffDataset
    {
        return StaticTakeoffDataset::query()
            ->where('file_hash', $hash)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The marked (annotated) PDF for an uploaded drawing — absolute path and
     * page count — or null when the drawing has no dataset or its dataset has
     * no marked copy; the caller then shows the plain upload as before.
     *
     * @return array{path: string, pages: int}|null
     */
    public function markedFor(Upload $upload): ?array
    {
        if (! config('static_takeoff.enabled') || blank($upload->path)) {
            return null;
        }

        $datasetId = Cache::remember(
            "static-takeoff:upload:{$upload->id}:dataset",
            now()->addHour(),
            fn () => $this->findByFile(Storage::disk((string) config('ai.storage.disk'))->path($upload->path))?->id ?? 0,
        );

        $dataset = $datasetId ? StaticTakeoffDataset::query()->find($datasetId) : null;
        $disk = Storage::disk('local');

        if ($dataset === null || blank($dataset->marked_pdf_path) || ! $disk->exists($dataset->marked_pdf_path)) {
            return null;
        }

        $path = $disk->path($dataset->marked_pdf_path);

        if (! $dataset->marked_page_count) {
            $dataset->marked_page_count = $this->countPages($path);
            $dataset->save();
        }

        return ['path' => $path, 'pages' => (int) $dataset->marked_page_count];
    }

    public function markedPdfFor(Upload $upload): ?string
    {
        return $this->markedFor($upload)['path'] ?? null;
    }

    private function countPages(string $path): ?int
    {
        $info = Process::timeout(30)->run([(string) config('ai.storage.pdfinfo'), $path]);

        return $info->successful() && preg_match('/^Pages:\s+(\d+)/m', $info->output(), $m) ? (int) $m[1] : null;
    }
}
