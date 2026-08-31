<?php

namespace App\Http\Resources;

use App\Models\TimerSession;
use App\Services\TimeTracking\TimerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimerSession */
class TimerSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job' => $this->job ? ['id' => $this->job->id, 'name' => $this->job->name] : null,
            'jobTask' => $this->jobTask ? ['id' => $this->jobTask->id, 'title' => $this->jobTask->title] : null,
            'taskLabel' => $this->task_label,
            'description' => $this->description,
            'startedAt' => $this->started_at->toISOString(),
            'status' => $this->status,
            'billable' => $this->billable,
            // Recomputed on every request — never trusted from a prior response.
            'elapsedSeconds' => app(TimerService::class)->elapsedSeconds($this->resource),
        ];
    }
}
