<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'detail' => $this->detail,
            // Workflow notifications deep-link to the screen needing attention.
            'link' => $this->link,
            'timestamp' => $this->created_at->toISOString(),
            'unread' => $this->read_at === null,
        ];
    }
}
