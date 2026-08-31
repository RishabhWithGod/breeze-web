<?php

namespace App\Http\Resources;

use App\Models\FeedItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row on one of the icon-list panels.
 *
 * `meta` is never the stored column verbatim — that column holds whatever
 * string a row was created with, which for anything older than the request
 * that made it is simply wrong ("Today, 10:23 AM" does not stay true).
 * `created_at` is always real, so the relative age shown is always real too.
 *
 * @mixin FeedItem
 */
class FeedItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'segments' => $this->segments,
            'detail' => $this->detail,
            'meta' => $this->created_at?->diffForHumans() ?? $this->meta,
            'icon' => $this->icon,
            'tile' => $this->tile,
        ];
    }
}
