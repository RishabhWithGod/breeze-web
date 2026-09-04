<?php

namespace App\Http\Resources;

use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Job */
class JobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'client' => $this->client,
            'location' => $this->location,
            'description' => $this->description,
            'jobType' => $this->job_type,
            'status' => $this->status,
            // Assigned after intake, so absent on a freshly created job.
            'foreman' => $this->foreman ? [
                'name' => $this->foreman->name,
                'initials' => $this->foreman->initials,
            ] : null,
            /*
             * The crew the job is handed to. Named `teamName` rather than
             * `team` because `team` on the detail resource already means the
             * people staffed onto the job, which is a different list.
             */
            'teamName' => $this->team?->name,
            // ISO strings throughout — the client formats with date-fns.
            'startDate' => $this->start_date?->toISOString(),
            'endDate' => $this->end_date?->toISOString(),
            'budget' => $this->budget === null ? null : (float) $this->budget,
            'isArchived' => $this->archived_at !== null,
            'teamCount' => $this->team_members_count ?? 0,
            'estimateCount' => $this->estimates_count ?? 0,
            // Who's currently staffed, and in what role — the same assignments
            // made on the job's own detail screen, surfaced here so the list
            // doesn't require opening every job to see who's on it.
            'assignments' => JobAssignmentResource::collection(
                $this->whenLoaded('activeAssignments'),
            )->resolve(),
            'options' => [
                'createEstimate' => $this->create_estimate,
                'assignTeam' => $this->assign_team,
                'notifyClient' => $this->notify_client,
            ],
        ];
    }
}
