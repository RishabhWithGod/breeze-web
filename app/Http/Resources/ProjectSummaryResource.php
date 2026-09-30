<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\Upload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A takeoff as it appears in the history table.
 *
 * @mixin Project
 */
class ProjectSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Upload|null $drawing */
        $drawing = $this->takeoffDrawing();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'date' => ($this->completed_at ?? $this->created_at)->toISOString(),
            'status' => $this->status,
            'items' => $this->items_count,
            // The drawing itself — its own name and format, not the project's.
            'drawingName' => $drawing?->title ?: $drawing?->name,
            'format' => $drawing?->format,
            'pageCount' => $this->page_count,
            'uploadedAt' => ($drawing?->created_at ?? $this->created_at)->toISOString(),
            // Read off `status` and `review_status` together — a review
            // pending or in progress says more than the takeoff's own status
            // does once one has started.
            'reviewStatus' => match (true) {
                $this->status === 'processing' => 'processing',
                $this->status === 'failed' => 'failed',
                $this->status === 'converted' => 'converted',
                in_array($this->review_status, ['pending', 'in-review'], true) => 'ready-for-review',
                $this->status === 'completed' => 'completed',
                default => 'draft',
            },
        ];
    }
}
