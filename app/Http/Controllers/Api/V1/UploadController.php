<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\Upload;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Uploads a project's drawing and hands it to the engine — mobile's
 * counterpart to web's `UploadController::store()`. Same transaction, same
 * two queued jobs (`RenderDrawingPreviews` for page images, `ProcessTakeoffRun`
 * for the analysis itself); a mobile client polls
 * `GET takeoffs/{project}/processing` for progress rather than waiting here,
 * exactly as the browser polls its own Processing screen.
 */
class UploadController extends Controller
{
    use ApiResponses;

    public function store(StoreUploadRequest $request, TakeoffOrchestrator $orchestrator): JsonResponse
    {
        if (! $orchestrator->configured()) {
            return $this->fail('The AI takeoff service is not configured.', 503);
        }

        $files = $request->file('files');
        $disk = config('takeoff.uploads.disk');
        $directory = config('takeoff.uploads.directory');

        [$project, $primaryUpload] = DB::transaction(function () use ($request, $files, $disk, $directory) {
            $project = $request->user()->projects()->findOrFail($request->integer('project_id'));

            $project->update([
                'drawing_name' => $files[0]->getClientOriginalName(),
                'status' => 'processing',
                'review_status' => 'none',
                'started_at' => $project->started_at ?? now(),
            ]);

            $uploads = [];

            foreach ($files as $file) {
                $originalName = $file->getClientOriginalName();
                $path = $file->store($directory, $disk);

                $uploads[] = $project->uploads()->create([
                    'user_id' => $request->user()->id,
                    'addendum_for_estimate_id' => $request->integer('addendum_for_estimate_id') ?: null,
                    'name' => $originalName,
                    'format' => Upload::formatFor($originalName),
                    'size_bytes' => $file->getSize(),
                    'path' => $path,
                    'status' => 'processing',
                ]);
            }

            $primary = collect($uploads)->firstWhere('format', 'PDF') ?? $uploads[0];
            $project->update(['selected_upload_id' => $primary->id]);

            return [$project, $primary];
        });

        $aiJob = $orchestrator->open($project, $primaryUpload, $request->user());

        RenderDrawingPreviews::dispatch($primaryUpload->id);
        ProcessTakeoffRun::dispatch($aiJob->id);

        return $this->created([
            'projectId' => $project->id,
            'aiJobId' => $aiJob->id,
            'status' => $aiJob->status,
        ], 'Drawing submitted for analysis.');
    }
}
