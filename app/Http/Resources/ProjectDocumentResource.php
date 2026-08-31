<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * One drawing PDF defined against a project.
 *
 * `available` is resolved here rather than in the client so a row whose file has
 * gone from the disk can say so instead of offering a link that 404s.
 *
 * @mixin Upload
 */
class ProjectDocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = filled($this->path)
            && Storage::disk((string) config('takeoff.uploads.disk'))->exists($this->path);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'label' => $this->label(),
            'format' => $this->format,
            // Raw bytes; the client renders them with formatFileSize().
            'sizeBytes' => $this->size_bytes,
            'pageCount' => $this->page_count,
            'uploadedAt' => $this->created_at->toISOString(),
            'available' => $available,
            'url' => $available
                ? route('projects.documents.show', [
                    'project' => $this->project_id,
                    'document' => $this->id,
                ])
                : null,
        ];
    }
}
