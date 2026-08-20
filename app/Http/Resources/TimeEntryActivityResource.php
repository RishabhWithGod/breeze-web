<?php

namespace App\Http\Resources;

use App\Models\TimeEntryActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntryActivity */
class TimeEntryActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'description' => $this->description,
            'actor' => $this->user?->name,
            'timestamp' => $this->created_at->toISOString(),
        ];
    }
}
