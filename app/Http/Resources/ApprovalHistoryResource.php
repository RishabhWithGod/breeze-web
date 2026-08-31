<?php

namespace App\Http\Resources;

use App\Models\ApprovalHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApprovalHistory */
class ApprovalHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'subject' => $this->subject,
            'description' => $this->description,
            'from' => $this->from_value,
            'to' => $this->to_value,
            'actor' => $this->actor?->name,
            'timestamp' => $this->created_at->toISOString(),
        ];
    }
}
