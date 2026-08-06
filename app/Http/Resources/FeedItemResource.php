<?php

namespace App\Http\Resources;

use App\Models\FeedItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeedItem */
class FeedItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'segments' => $this->segments,
            'detail' => $this->detail,
            'meta' => $this->meta,
            'icon' => $this->icon,
            'tile' => $this->tile,
        ];
    }
}
