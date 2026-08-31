<?php

namespace App\Services\Takeoff;

use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The drawing PDFs a project holds.
 *
 * Files land on the same disk and in the same directory as an AI takeoff upload,
 * and are recorded as `uploads` rows, so a drawing defined on the Projects screen
 * is the same artefact the engine later analyses — there is no second kind of
 * project file.
 */
class ProjectDocumentStore
{
    /**
     * Stores each file and records it against the project.
     *
     * `$titles` are the optional per-file labels, positionally matched to
     * `$files`, exactly as the multipart form sends them.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<int, string|null>  $titles
     * @return list<Upload>
     */
    public function add(Project $project, array $files, array $titles, User $owner): array
    {
        $disk = (string) config('takeoff.uploads.disk');
        $directory = (string) config('takeoff.uploads.directory');

        $uploads = [];

        foreach (array_values($files) as $index => $file) {
            $name = $file->getClientOriginalName();

            $uploads[] = $project->uploads()->create([
                'user_id' => $owner->id,
                'name' => $name,
                'title' => filled($titles[$index] ?? null) ? trim((string) $titles[$index]) : null,
                'format' => Upload::formatFor($name),
                'size_bytes' => $file->getSize(),
                'path' => $file->store($directory, $disk),
                // Nothing has been submitted to the engine, so the file is simply
                // on record — `processing` would claim a run that does not exist.
                'status' => 'completed',
            ]);
        }

        return $uploads;
    }

    /** Removes the file from the disk, then the row. */
    public function remove(Upload $upload): void
    {
        $disk = Storage::disk((string) config('takeoff.uploads.disk'));

        if (filled($upload->path) && $disk->exists($upload->path)) {
            $disk->delete($upload->path);
        }

        $upload->delete();
    }

    /** The drawing a takeoff would run against: the first PDF on record. */
    public function primaryDrawingName(Project $project): ?string
    {
        return $project->uploads()->oldest()->value('name');
    }
}
