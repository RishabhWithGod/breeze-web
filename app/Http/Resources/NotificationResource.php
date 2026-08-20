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
            'category' => AppNotification::categoryFor($this->type),
            'title' => $this->title,
            'detail' => $this->detail,
            // Workflow notifications deep-link to the screen needing attention.
            'link' => $this->link,
            // Structured, labelled destinations — falls back to the single
            // legacy `link` for notification classes that never set `data`.
            'actions' => $this->actions(),
            'timestamp' => $this->created_at->toISOString(),
            'readAt' => $this->read_at?->toISOString(),
            'unread' => $this->read_at === null,
        ];
    }

    /** @return list<array{label: string, href: string}> */
    private function actions(): array
    {
        $actions = $this->data['actions'] ?? null;

        if (is_array($actions) && $actions !== []) {
            return $actions;
        }

        return $this->link ? [['label' => 'View', 'href' => $this->link]] : [];
    }
}
