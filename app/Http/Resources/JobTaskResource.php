<?php

namespace App\Http\Resources;

use App\Models\JobTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One task, in the shape every scheduling panel reads.
 *
 * `isOverdue` and `daysLate` are computed server-side rather than derived in the
 * browser: "late" depends on today's date, and the server's clock is the one the
 * dates were saved against.
 *
 * @mixin JobTask
 */
class JobTaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduleId' => $this->job_schedule_id,
            'jobId' => $this->job_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'category' => $this->category,
            'estimatedHours' => $this->estimated_hours === null ? null : (float) $this->estimated_hours,
            'actualHours' => (float) $this->actual_hours,
            /* Plain dates: the grid buckets by them and a timestamp would drag the
               browser's timezone into a decision the server already made. */
            'startsOn' => $this->starts_on?->toDateString(),
            'endsOn' => $this->ends_on?->toDateString(),
            'baselineEndsOn' => $this->baseline_ends_on?->toDateString(),
            'completionPct' => $this->completion_pct,
            'position' => $this->position,
            'isMilestone' => $this->is_milestone,
            'notes' => $this->notes,
            'completedAt' => $this->completed_at?->toISOString(),
            'isOverdue' => $this->isOverdue(),
            'daysLate' => $this->daysLate(),
            'slippedDays' => $this->slippedDays(),
            'durationDays' => $this->starts_on && $this->ends_on
                ? (int) $this->starts_on->diffInDays($this->ends_on) + 1
                : 0,
            'assignments' => $this->whenLoaded(
                'assignments',
                fn () => $this->assignments->map(fn ($assignment) => [
                    'id' => $assignment->id,
                    'role' => $assignment->role,
                    'member' => $assignment->member ? [
                        'id' => $assignment->member->id,
                        'name' => $assignment->member->name,
                        'initials' => $assignment->member->initials,
                        'role' => $assignment->member->role,
                    ] : null,
                ])->values(),
                [],
            ),
            'dependencies' => $this->whenLoaded(
                'dependencies',
                fn () => $this->dependencies->map(fn ($edge) => [
                    'id' => $edge->id,
                    'dependsOnId' => $edge->depends_on_id,
                    'dependsOnTitle' => $edge->dependsOn?->title,
                    'type' => $edge->type,
                    'typeLabel' => $edge->label(),
                    'lagDays' => $edge->lag_days,
                ])->values(),
                [],
            ),
            'commentCount' => $this->comments_count ?? 0,
            'attachmentCount' => $this->attachments_count ?? 0,
        ];
    }
}
