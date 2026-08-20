<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Writes and removes the bytes behind a `Document`, and keeps a version
 * family's `is_latest` flag pointing at exactly one row.
 *
 * A new document starts its own family (`version` 1, `version_root_id` null).
 * Uploading "a new version" of an existing one never overwrites the old
 * file — it stores the new bytes alongside it, bumps the version number, and
 * flips `is_latest` from the old row to the new one, so History can always
 * open what a prior version actually looked like.
 */
class DocumentStore
{
    /** @param  array<string, mixed>  $attributes */
    public function create(UploadedFile $file, array $attributes, User $user): Document
    {
        $path = $file->store(config('documents.directory'), config('documents.disk'));

        return Document::create([
            ...$attributes,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'file_size' => $file->getSize(),
            'uploaded_by' => $user->id,
            'version' => 1,
            'version_root_id' => null,
            'is_latest' => true,
        ]);
    }

    public function createNewVersion(Document $original, UploadedFile $file, User $user, ?string $description = null): Document
    {
        $rootId = $original->familyRootId();
        $nextVersion = (int) Document::withTrashed()
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('version_root_id', $rootId))
            ->max('version') + 1;

        $path = $file->store(config('documents.directory'), config('documents.disk'));

        $version = Document::create([
            'name' => $original->name,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'extension' => strtolower($file->getClientOriginalExtension()),
            'file_size' => $file->getSize(),
            'document_type' => $original->document_type,
            'job_id' => $original->job_id,
            'estimate_id' => $original->estimate_id,
            'folder_id' => $original->folder_id,
            'visibility' => $original->visibility,
            'description' => $description ?? $original->description,
            'uploaded_by' => $user->id,
            'version_root_id' => $rootId,
            'version' => $nextVersion,
            'is_latest' => true,
        ]);

        Document::where(fn ($q) => $q->where('id', $rootId)->orWhere('version_root_id', $rootId))
            ->where('id', '!=', $version->id)
            ->update(['is_latest' => false]);

        return $version;
    }

    /**
     * Deletes one version's bytes and row. If it was the family's latest and
     * other versions remain, the next-highest version becomes latest instead
     * of leaving the family without one.
     */
    public function delete(Document $document): void
    {
        $rootId = $document->familyRootId();
        $wasLatest = $document->is_latest;

        Storage::disk(config('documents.disk'))->delete($document->storage_path);
        $document->delete();

        if (! $wasLatest) {
            return;
        }

        $next = Document::where(fn ($q) => $q->where('id', $rootId)->orWhere('version_root_id', $rootId))
            ->orderByDesc('version')
            ->first();

        $next?->update(['is_latest' => true]);
    }
}
