<?php

namespace App\Http\Resources;

use App\Models\Project;
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'date' => ($this->completed_at ?? $this->created_at)->toISOString(),
            'status' => $this->status,
            'items' => $this->items_count,
        ];
    }
}
