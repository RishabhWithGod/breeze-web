<?php

namespace App\Services\Takeoff;

use App\Models\Upload;
use Illuminate\Support\Facades\Storage;

/**
 * Taking a client's drawing PDF back off the disk.
 *
 * Nothing is added here: every drawing arrives through the AI Takeoff upload,
 * which records its own `uploads` row. Which drawing a takeoff runs against is
 * the client's own business — see `Project::takeoffDrawing()`.
 */
class ProjectDocumentStore
{
    /** Removes the file from the disk, then the row. */
    public function remove(Upload $upload): void
    {
        $disk = Storage::disk((string) config('takeoff.uploads.disk'));

        if (filled($upload->path) && $disk->exists($upload->path)) {
            $disk->delete($upload->path);
        }

        $upload->delete();
    }
}
