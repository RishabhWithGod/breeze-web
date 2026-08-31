<?php

namespace App\Http\Resources;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job as the scheduling screens see it: what it needs, and what it is worth.
 *
 * @mixin Job
 */
class SchedulableJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'location' => $this->location,
            'jobType' => $this->job_type,
            'status' => $this->status,
            'priority' => $this->priority ?? 'medium',
            'estimatedHours' => $this->estimated_hours === null ? null : (float) $this->estimated_hours,
            'requiredSkills' => $this->required_skills ?? [],
            'value' => $this->budget === null ? null : (float) $this->budget,
            'startDate' => $this->start_date?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'foreman' => $this->foreman ? [
                'name' => $this->foreman->name,
                'initials' => $this->foreman->initials,
            ] : null,
            // Present on the calendar's strip so an already-booked job can say so
            // rather than looking unassigned.
            'shiftCount' => $this->schedules_count ?? 0,
        ];
    }
}
