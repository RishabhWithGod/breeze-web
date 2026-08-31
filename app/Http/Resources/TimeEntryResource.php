<?php

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntry */
class TimeEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Cost is sensitive: only included for whoever is allowed to see job
        // costs, not merely hidden client-side in an otherwise-complete payload.
        $canViewCosts = (bool) $request->user()?->can('viewJobCosts', TimeEntry::class);

        return [
            'id' => $this->id,
            'job' => $this->job ? [
                'id' => $this->job->id,
                'name' => $this->job->name,
                'client' => $this->job->client,
                'status' => $this->job->status,
            ] : null,
            'jobTask' => $this->jobTask ? [
                'id' => $this->jobTask->id,
                'title' => $this->jobTask->title,
            ] : null,
            'taskLabel' => $this->task_label,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'initials' => $this->user->initials,
                'role' => $this->user->role,
            ] : null,
            'teamMember' => $this->teamMember ? [
                'id' => $this->teamMember->id,
                'name' => $this->teamMember->name,
                'role' => $this->teamMember->role,
            ] : null,
            'date' => $this->date->toDateString(),
            'startTime' => $this->start_time,
            'endTime' => $this->end_time,
            'breakMinutes' => $this->break_minutes,
            'hours' => (float) $this->hours,
            'regularHours' => (float) $this->regular_hours,
            'overtimeHours' => (float) $this->overtime_hours,
            'description' => $this->description,
            'billable' => $this->billable,
            'source' => $this->source,
            'status' => $this->status,
            'submittedAt' => $this->submitted_at?->toISOString(),
            'approvedAt' => $this->approved_at?->toISOString(),
            'rejectedAt' => $this->rejected_at?->toISOString(),
            'approver' => $this->approver?->name,
            'rejecter' => $this->rejecter?->name,
            'rejectionReason' => $this->rejection_reason,
            'isEditable' => $this->isEditable(),
            'isLocked' => $this->isLocked(),
            'isCorrection' => $this->isCorrection(),
            'laborCost' => $canViewCosts && $this->labor_cost !== null ? (float) $this->labor_cost : null,
            'billableAmount' => $canViewCosts && $this->billable_amount !== null ? (float) $this->billable_amount : null,
            'billableRate' => $canViewCosts && $this->billable_rate !== null ? (float) $this->billable_rate : null,
            'costRate' => $canViewCosts && $this->cost_rate !== null ? (float) $this->cost_rate : null,
        ];
    }
}
