<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;
use App\Models\Project;
use App\Models\Upload;
use Illuminate\Support\Collection;

/**
 * The Project/PDF pickers offered on the manual Create Job and Create Estimate
 * screens, so those forms can link back to a drawing already run through AI
 * Takeoff instead of starting a disconnected record.
 */
class TakeoffLinkOptions
{
    /** @return Collection<int, array<string, mixed>> */
    public function projects(): Collection
    {
        return Project::orderBy('name')->get(['id', 'name', 'client', 'location', 'due_date', 'project_type'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'client' => $project->client === 'Unassigned' ? null : $project->client,
                'location' => $project->location,
                'dueDate' => $project->due_date?->toDateString(),
                'projectType' => $project->project_type,
            ]);
    }

    /**
     * Every uploaded drawing, with the estimate already raised against it
     * (there is at most one per takeoff), so picking a PDF that already has
     * one links to it rather than offering to create a duplicate.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function uploads(): Collection
    {
        $uploads = Upload::orderBy('name')->get(['id', 'name', 'project_id']);

        $estimatesByUpload = AiResult::whereIn('upload_id', $uploads->pluck('id'))
            ->whereNotNull('estimate_id')
            ->with('estimate')
            ->get()
            ->keyBy('upload_id');

        return $uploads->map(function (Upload $upload) use ($estimatesByUpload) {
            $estimate = $estimatesByUpload->get($upload->id)?->estimate;

            return [
                'id' => $upload->id,
                'name' => $upload->label(),
                'projectId' => $upload->project_id,
                'estimate' => $estimate ? [
                    'id' => $estimate->id,
                    'number' => $estimate->number,
                    'amount' => (float) $estimate->grand_total,
                    'status' => $estimate->status,
                    'editUrl' => route('estimates.edit', $estimate),
                ] : null,
            ];
        });
    }
}
