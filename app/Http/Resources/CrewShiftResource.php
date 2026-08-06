<?php

namespace App\Http\Resources;

use App\Models\CrewShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One crew shift, in the shape a calendar block renders.
 *
 * @mixin CrewShift
 */
class CrewShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'jobId' => $this->job_id,
            'jobName' => $this->job?->name ?? 'Unknown job',
            'client' => $this->job?->client,
            'location' => $this->job?->location,
            'jobType' => $this->job?->job_type,
            'priority' => $this->job?->priority ?? 'medium',
            'crew' => $this->crew,
            // The grid buckets blocks by this exact key, so it is a plain date.
            'date' => $this->scheduled_date->toDateString(),
            'startTime' => substr((string) $this->start_time, 0, 5),
            'startLabel' => $this->startLabel(),
            'endLabel' => $this->endLabel(),
            'durationHours' => (float) $this->duration_hours,
            'status' => $this->status,
            'notes' => $this->notes,
            'member' => $this->teamMember ? [
                'id' => $this->teamMember->id,
                'name' => $this->teamMember->name,
                'initials' => $this->teamMember->initials,
                'role' => $this->teamMember->role,
            ] : null,
        ];
    }
}
