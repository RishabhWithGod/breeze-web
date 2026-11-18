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
            // Where a click on this takeoff should go. A takeoff waiting on its review is picked up
            // at the review screen — the same place the Resume button points — so nobody has to go
            // through the detail screen to get to it. Anything else has no answer here and the list
            // falls back to the drawing's detail screen.
            'reviewUrl' => $this->reviewUrl(),
            // A takeoff still being read keeps running on the server whether or not anyone is watching it;
            // this is the way back to the screen that shows how far it has got.
            'processingUrl' => $this->status === 'processing' ? route('processing.show', $this->id, absolute: false) : null,
        ];
    }

    /** The review screen of this takeoff's latest result, while that review is still open. */
    private function reviewUrl(): ?string
    {
        if (! in_array($this->review_status, ['pending', 'in-review'], true)
            || in_array($this->status, ['processing', 'failed', 'converted'], true)) {
            return null;
        }

        $result = $this->aiResults()->first();

        return $result !== null && ! $result->isFinalised()
            ? route('reviews.show', $result, absolute: false)
            : null;
    }
}
