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
            // Booking a crew reads both: the days a job runs are the days it
            // was given, not a number somebody guesses at the modal.
            'endDate' => $this->end_date?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            /*
             * The crew this job is handed to. What every scheduling screen
             * groups and labels by — a shift is booked for a team.
             */
            'teamName' => $this->team?->name,
            /*
             * Who is already on this job.
             *
             * Both are assigned per task, so a job can have several of each —
             * or, before its work is broken down, none. Older jobs still carry
             * a foreman of their own; that is the fallback, not the answer.
             */
            'foremen' => $this->assignedForemen(),
            'supervisors' => $this->assignedSupervisors(),
            // Present on the calendar's strip so an already-booked job can say so
            // rather than looking unassigned.
            'shiftCount' => $this->schedules_count ?? 0,
        ];
    }
}
