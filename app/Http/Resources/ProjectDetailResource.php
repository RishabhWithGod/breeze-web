<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A takeoff with everything the results dashboard renders.
 *
 * @mixin Project
 */
class ProjectDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'drawingName' => $this->drawing_name,
            'discipline' => $this->discipline,
            'status' => $this->status,
            'createdAt' => ($this->started_at ?? $this->created_at)->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'pageCount' => $this->page_count,
            'overallConfidence' => $this->overall_confidence,
            // resolve() on each nested collection, otherwise they serialise as
            // { data: [...] } objects and the page receives the wrong shape.
            'sheets' => DrawingSheetResource::collection($this->sheets)->resolve(),
            'symbols' => DetectedSymbolResource::collection($this->symbols)->resolve(),
            'metrics' => ProjectMetricResource::collection($this->metrics)->resolve(),
            'activity' => ProjectActivityResource::collection($this->activities)->resolve(),
        ];
    }
}
