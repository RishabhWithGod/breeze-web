<?php

namespace App\Services\Takeoff;

use App\Models\AiResult;
use App\Models\Upload;
use App\Services\Clients\ClientDirectory;
use Illuminate\Support\Collection;

/**
 * The PDF picker offered on the manual Create Job and Create Estimate screens,
 * so those forms can link back to a drawing already run through AI Takeoff
 * instead of starting a disconnected record.
 *
 * The client half of that pairing lives in {@see ClientDirectory} — clients
 * are projects, so the same picker that names the client also scopes the
 * drawings offered below it.
 */
class TakeoffLinkOptions
{
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
