<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Document */
class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'originalFilename' => $this->original_filename,
            'documentType' => $this->document_type,
            'extension' => $this->extension,
            'mimeType' => $this->mime_type,
            'fileSize' => (int) $this->file_size,
            'version' => $this->version,
            'versionLabel' => $this->versionLabel(),
            'isLatest' => $this->is_latest,
            'isArchived' => $this->is_archived,
            'visibility' => $this->visibility,
            'description' => $this->description,
            'jobId' => $this->job_id,
            'jobName' => $this->whenLoaded('job', fn () => $this->job?->name),
            // The takeoff it is filed under. Its name is not sent: the list is
            // opened per takeoff and says so in its own header.
            'projectId' => $this->project_id,
            'estimateId' => $this->estimate_id,
            'estimateNumber' => $this->whenLoaded('estimate', fn () => $this->estimate?->number),
            'folderId' => $this->folder_id,
            'folderName' => $this->whenLoaded('folder', fn () => $this->folder?->name),
            'uploadedBy' => $this->uploaded_by,
            'uploaderName' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'uploadedAt' => $this->created_at?->toISOString(),
            'modifiedAt' => $this->updated_at?->toISOString(),
            'isFavorite' => $this->isFavoritedBy($user),
            'isShared' => $this->isSharedWith($user),
            'aiTakeoffProjectId' => $this->upload_id ? $this->upload?->project_id : null,
            'canEdit' => $user ? $request->user()->can('update', $this->resource) : false,
            'canDelete' => $user ? $request->user()->can('delete', $this->resource) : false,
            'canShare' => $user ? $request->user()->can('share', $this->resource) : false,
            'downloadUrl' => route('documents.download', $this->id, absolute: false),
            'previewUrl' => route('documents.preview', $this->id, absolute: false),
            'historyUrl' => route('documents.history', $this->id, absolute: false),
        ];
    }
}
