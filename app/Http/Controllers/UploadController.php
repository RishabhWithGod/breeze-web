<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUploadRequest;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\Upload;
use App\Services\Ai\TakeoffOrchestrator;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class UploadController extends Controller
{
    public function create(Request $request, TakeoffOrchestrator $orchestrator): Response
    {
        // Opened for a particular client, this is that client's next step.
        $preselected = $this->preselectedClient($request);

        if ($preselected !== null) {
            $client = $request->user()->projects()->find($preselected);

            if ($client !== null) {
                app(TakeoffFlow::class)->remember($client);
            }
        }

        return Inertia::render('Upload', [
            'projects' => $request->user()->projects()
                ->orderByDesc('created_at')
                ->get(['id', 'name'])
                ->map(fn ($project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                ]),
            'selectedProjectId' => $preselected,
            /*
             * A takeoff already running, unless it is the very one this screen
             * opened for — that is the resume, not a second start.
             */
            'unfinishedTakeoff' => app(TakeoffFlow::class)->inProgress($request, $preselected),
            /*
             * The dropzone renders the limit the request actually enforces — the
             * lower of the product's setting and PHP's own ceiling — so it can
             * never promise more than the server accepts.
             */
            'limits' => [
                'maxFiles' => config('takeoff.uploads.max_files'),
                'maxFileSizeMb' => UploadLimits::effectiveMb(),
                'extensions' => config('takeoff.uploads.extensions'),
                'serverHint' => UploadLimits::phpHint(),
            ],
            // Surfaced so the screen can explain why a run cannot start, rather
            // than failing only at submit time. Whether the engine is *answering* is
            // deliberately not asked here: a page render must never wait on it. The
            // screen polls `engineStatusUrl` for that.
            'aiConfigured' => $orchestrator->configured(),
            'engineStatusUrl' => route('ai.engine-status'),
        ]);
    }

    /**
     * The client the picker should open on.
     *
     * Either named outright by a link that came from a client's own screen, or
     * remembered from creating one — someone who has just made a client and
     * come here to upload its drawing means that client. Both are checked
     * against the signed-in user's own clients, so neither can point the
     * picker at someone else's record.
     *
     * The remembered one is read once and cleared: it answers "the client you
     * just created", not "the client you always want".
     */
    private function preselectedClient(Request $request): ?int
    {
        $requested = $request->integer('project') ?: null;
        $remembered = $request->session()->pull('takeoff.preselected_client');

        $candidate = $requested ?? $remembered;

        if ($candidate === null) {
            return null;
        }

        return $request->user()->projects()->whereKey($candidate)->value('id');
    }

    /**
     * Engine readiness, for the upload screen's indicator.
     *
     * Its own endpoint rather than a page prop, and cached in the client, so a slow
     * or missing engine can never hold up a render.
     */
    public function engineStatus(TakeoffOrchestrator $orchestrator): JsonResponse
    {
        $health = $orchestrator->health();

        return response()->json([
            'configured' => $orchestrator->configured(),
            'online' => $health['ok'],
            'engine' => $health['detail']['app'] ?? null,
            'version' => $health['detail']['version'] ?? null,
        ]);
    }

    /**
     * Stores the drawing set and hands it to the engine.
     *
     * The request does four things and returns: validate, store the files, record the
     * upload and the run, queue the analysis. Nothing that talks to the engine
     * happens here — a 50 MB drawing takes the engine minutes, and an HTTP request
     * must never wait for that.
     */
    public function store(StoreUploadRequest $request, TakeoffOrchestrator $orchestrator): RedirectResponse
    {
        if (! $orchestrator->configured()) {
            return back()->withErrors([
                'files' => 'The AI takeoff service is not configured. Set AI_API_BASE_URL in .env before running a takeoff.',
            ]);
        }

        $requestBegan = microtime(true);
        $files = $request->file('files');
        $disk = config('takeoff.uploads.disk');
        $directory = config('takeoff.uploads.directory');
        $bytes = 0;
        $fileSaveMs = 0.0;

        [$project, $primaryUpload] = DB::transaction(function () use ($request, $files, $disk, $directory, &$bytes, &$fileSaveMs) {
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
                $bytes += (int) $file->getSize();

                // Timed on its own: moving a 100 MB drawing onto the disk is the one
                // genuinely slow thing this request does.
                $saveBegan = microtime(true);
                $path = $file->store($directory, $disk);
                $fileSaveMs += (microtime(true) - $saveBegan) * 1000;

                $uploads[] = $project->uploads()->create([
                    'user_id' => $request->user()->id,
                    'name' => $originalName,
                    'format' => Upload::formatFor($originalName),
                    'size_bytes' => $file->getSize(),
                    'path' => $path,
                    'status' => 'processing',
                ]);
            }

            // The drawing the analysis runs against: the first PDF if there is
            // one, otherwise the first file uploaded.
            $primary = collect($uploads)->firstWhere('format', 'PDF') ?? $uploads[0];

            // Uploading a drawing to run a takeoff on it *is* choosing it, so
            // the client screen shows it selected rather than still pointing at
            // whatever was there before.
            $project->update(['selected_upload_id' => $primary->id]);

            return [$project, $primary];
        });

        $aiJob = $orchestrator->open($project, $primaryUpload, $request->user());

        $dispatchBegan = microtime(true);
        // Queued first so a second worker can render page images while the engine is
        // still analysing. It is independent of the run and never blocks it.
        RenderDrawingPreviews::dispatch($primaryUpload->id);
        ProcessTakeoffRun::dispatch($aiJob->id);
        $dispatchMs = (microtime(true) - $dispatchBegan) * 1000;

        // The upload half of the lifecycle, logged where it happens. The worker
        // records the rest onto the same `ai_jobs` row.
        Log::info('Takeoff upload timings', [
            'ai_job_id' => $aiJob->id,
            'files' => count($files),
            'bytes' => $bytes,
            'file_save_ms' => round($fileSaveMs, 1),
            'queue_dispatch_ms' => round($dispatchMs, 1),
            'request_total_ms' => round((microtime(true) - $requestBegan) * 1000, 1),
        ]);

        return redirect()->route('processing.show', $project);
    }
}
