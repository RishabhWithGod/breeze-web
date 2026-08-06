<?php

namespace App\Http\Resources;

use App\Models\JobAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin JobAssignment */
class JobAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'roleLabel' => $this->roleLabel(),
            'name' => $this->name,
            'notes' => $this->notes,
            'teamMemberId' => $this->team_member_id,
            'assignedBy' => $this->assigner?->name,
            'assignedAt' => $this->assigned_at->toISOString(),
            'releasedAt' => $this->released_at?->toISOString(),
            'active' => $this->isActive(),
        ];
    }
}
