<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project as the Projects list shows it.
 *
 * Wider than ProjectSummaryResource, which answers the takeoff history's
 * question ("what did this run count?"); this one answers the project's
 * ("whose is it, where is it, how many drawings does it hold?").
 *
 * @mixin Project
 */
class ProjectListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'client' => $this->client,
            'location' => $this->location,
            'discipline' => $this->discipline,
            'projectType' => $this->project_type,
            'status' => $this->status,
            // Set by `withCount('uploads')`; counted on demand otherwise.
            'documentsCount' => (int) ($this->uploads_count ?? $this->uploads()->count()),
            'itemsCount' => $this->items_count,
            'createdAt' => $this->created_at->toISOString(),
        ];
    }
}
