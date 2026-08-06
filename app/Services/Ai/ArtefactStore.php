<?php

namespace App\Services\Ai;

use App\Models\AiResult;
use App\Models\Project;
use App\Models\Upload;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Everything a takeoff writes to disk.
 *
 * Layout, per project: takeoffs/{project}/original_response.json,
 * final_response.json, previews/page-01.png, thumbnail.png, annotated.pdf,
 * final.pdf. Kept in one class so no other layer has to know the paths.
 */
class ArtefactStore
{
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('ai.storage.disk'));
    }

    public function directoryFor(Project $project): string
    {
        return trim((string) config('ai.storage.directory'), '/')."/{$project->id}";
    }

    /** Writes the AI response exactly as received. */
    public function putOriginalResponse(AiResult $result, array $payload): string
    {
        return $this->putJson($result->project, 'original_response.json', $payload);
    }

    /** Writes the reviewed response the job and estimate are built from. */
    public function putFinalResponse(AiResult $result, array $payload): string
    {
        return $this->putJson($result->project, 'final_response.json', $payload);
    }

    public function putJson(Project $project, string $name, array $payload): string
    {
        $path = $this->directoryFor($project).'/'.$name;

        $this->disk()->put($path, json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $path;
    }

    public function put(Project $project, string $name, string $contents): string
    {
        $path = $this->directoryFor($project).'/'.$name;
        $this->disk()->put($path, $contents);

        return $path;
    }

    public function absolutePath(string $path): string
    {
        return $this->disk()->path($path);
    }

    public function exists(?string $path): bool
    {
        return filled($path) && $this->disk()->exists($path);
    }

    /**
     * Renders page previews and a thumbnail for a PDF upload with `pdftoppm`.
     *
     * Previews are a convenience — the review screen falls back to a drawn
     * placeholder — so a missing binary is logged, never fatal.
     *
     * @return array{pages: int, previews: list<string>, thumbnail: ?string}
     */
    public function renderPreviews(Upload $upload): array
    {
        $result = ['pages' => 0, 'previews' => [], 'thumbnail' => null];

        if ($upload->format !== 'PDF' || ! $this->exists($upload->path)) {
            return $result;
        }

        $binary = (string) config('ai.storage.pdftoppm');
        $source = $this->absolutePath($upload->path);
        $directory = $this->directoryFor($upload->project).'/previews';
        $this->disk()->makeDirectory($directory);
        $prefix = $this->absolutePath($directory).'/page';

        $render = Process::timeout(120)->run([
            $binary,
            '-png',
            '-r', (string) config('ai.storage.preview_dpi'),
            '-l', (string) config('ai.storage.max_preview_pages'),
            $source,
            $prefix,
        ]);

        if ($render->failed()) {
            Log::warning('Page previews could not be rendered', [
                'upload_id' => $upload->id,
                'error' => str($render->errorOutput())->limit(300)->value(),
            ]);

            return $result;
        }

        $previews = collect($this->disk()->files($directory))
            ->filter(fn (string $file) => str_ends_with($file, '.png'))
            ->sort()
            ->values();

        $result['previews'] = $previews->all();
        $result['pages'] = $previews->count();
        $result['thumbnail'] = $previews->first();

        $upload->update([
            'page_count' => $result['pages'],
            'preview_paths' => $result['previews'],
            'thumbnail_path' => $result['thumbnail'],
        ]);

        return $result;
    }
}
