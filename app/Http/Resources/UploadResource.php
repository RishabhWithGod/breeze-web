<?php

namespace App\Http\Resources;

use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Upload */
class UploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'format' => $this->format,
            // Raw bytes; the client renders them with formatFileSize().
            'sizeBytes' => $this->size_bytes,
            'uploadedAt' => $this->created_at->toISOString(),
            'status' => $this->status,
        ];
    }
}
