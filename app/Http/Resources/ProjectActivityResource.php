<?php

namespace App\Http\Resources;

use App\Models\ProjectActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectActivity */
class ProjectActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'timestamp' => $this->occurred_at->toISOString(),
            'tone' => $this->tone,
        ];
    }
}
