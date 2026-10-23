<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\Estimate;
use App\Models\Upload;
use App\Services\Ai\TakeoffOrchestrator;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\Ownership;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /**
     * What the Upload screen needs — web's `UploadController::create()` props: the projects to
     * pick (named with their client), the one to open on, the addendum this is for, a takeoff
     * already on the go, the limits the request really enforces, and whether the engine is set up.
     */
    public function options(Request $request, TakeoffOrchestrator $orchestrator, TakeoffFlow $flow): JsonResponse
    {
        $user = $request->user();

        // Opened for an estimate's addendum — that estimate's own project is the one this runs against.
        $addendumFor = null;
        if ($request->integer('addendum_for')) {
            $addendumFor = Estimate::whereIn('user_id', Ownership::userIds($user))->find($request->integer('addendum_for'));
        }

        $requested = $addendumFor?->project_id ?? ($request->integer('project') ?: null);
        $preselected = $requested === null ? null : $user->projects()->whereKey($requested)->value('id');

        return $this->ok([
            'projects' => $user->projects()
                ->with('clientRecord:id,name')
                ->orderByDesc('created_at')
                ->get(['id', 'client_id', 'name'])
                ->map(fn ($project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'clientName' => $project->clientRecord?->name,
                ])->values()->all(),
            'selectedProjectId' => $preselected,
            'addendumFor' => $addendumFor === null ? null : [
                'estimateId' => $addendumFor->id,
                'estimateNumber' => $addendumFor->number,
                'projectName' => $addendumFor->project,
                'nextNumber' => Estimate::nextAddendumNumber($addendumFor->id),
            ],
            'unfinishedTakeoff' => $this->unfinished($user, $flow, $preselected),
            'limits' => [
                'maxFiles' => config('takeoff.uploads.max_files'),
                'maxFileSizeMb' => UploadLimits::effectiveMb(),
                'extensions' => config('takeoff.uploads.extensions'),
                'serverHint' => UploadLimits::phpHint(),
            ],
            'aiConfigured' => $orchestrator->configured(),
        ]);
    }

    /** Whether the engine answers — polled by the Upload screen, never held up by a render. */
    public function engineStatus(TakeoffOrchestrator $orchestrator): JsonResponse
    {
        $health = $orchestrator->health();

        return $this->ok([
            'configured' => $orchestrator->configured(),
            'online' => $health['ok'],
        ]);
    }

    /**
     * A takeoff already part-way through, unless it is the very one this screen opened for —
     * that is the resume, not a second start. Web's `TakeoffFlow::inProgress()`.
     *
     * @return array<string, mixed>|null
     */
    private function unfinished($user, TakeoffFlow $flow, ?int $except): ?array
    {
        $id = $user->takeoff_flow_project_id;

        if ($id === null || $id === $except) {
            return null;
        }

        $project = \App\Models\Project::find($id);
        $step = $project === null ? null : $flow->stepForMobile($project);

        if ($step === null) {
            return null;
        }

        return [
            'stage' => $step['stage'],
            'projectId' => $step['projectId'],
            'estimateId' => $step['estimateId'],
            'jobId' => $step['jobId'],
            'projectName' => $project->name,
        ];
    }

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
                    'addendum_reason' => $request->input('addendum_reason') ?: null,
                    'affected_sheets' => $request->input('affected_sheets') ?: null,
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

        // Remembered so the flow can be left and picked up again — the
        // same call web's own `UploadController::store()` makes.
        app(TakeoffFlow::class)->remember($project, $request->user());

        $aiJob = $orchestrator->open($project, $primaryUpload, $request->user());

        RenderDrawingPreviews::dispatch($primaryUpload->id);
        ProcessTakeoffRun::dispatch($aiJob->id);

        // A static-mode match runs the job inline above (see
        // `ProcessTakeoffRun::__construct()`) — refreshed so a run that
        // already finished is reported as such immediately, not as the
        // `queued` status this object was created with.
        return $this->created([
            'projectId' => $project->id,
            'aiJobId' => $aiJob->id,
            'status' => $aiJob->refresh()->status,
        ], 'Drawing submitted for analysis.');
    }
}
