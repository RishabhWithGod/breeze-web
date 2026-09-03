<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectSummaryResource;
use App\Http\Resources\UploadResource;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\AiJob;
use App\Models\Project;
use App\Services\Ai\AiRunStatePresenter;
use App\Services\Ai\TakeoffOrchestrator;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live view of a run in flight.
 *
 * The engine analyses a drawing in one synchronous call, so progress is the stage
 * the queue worker has reached, recorded on the `ai_jobs` row. The browser polls
 * Laravel — never the engine directly.
 */
class ProcessingController extends Controller
{
    /**
     * How many times a poll will re-queue a run before it gives up and lets the
     * screen ask instead. Re-queueing cannot conjure a worker, so past a couple of
     * minutes of trying it is only filling the `jobs` table.
     */
    private const MAX_RESCUE_ATTEMPTS = 8;

    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        // Remembered so the flow can be left and picked up again.
        app(TakeoffFlow::class)->remember($project);

        $aiJob = $project->latestAiJob;

        return Inertia::render('Processing', [
            'project' => (new ProjectSummaryResource($project))->resolve(),
            'uploads' => UploadResource::collection($project->uploads)->resolve(),
            'stages' => config('takeoff.stages'),
            'run' => $this->runState($aiJob),
            /*
             * The gap the browser leaves between polls. With long polling on, the
             * request itself does the waiting and this is only the pause before
             * re-asking, so it is deliberately short.
             */
            'pollIntervalMs' => $this->holdSeconds() > 0
                ? 250
                : (int) config('takeoff.polling.interval_ms'),
        ]);
    }

    /**
     * Poll target for the processing screen.
     *
     * The analysis is one synchronous call made by a queue worker, so this only
     * reports the state that call has reached. It never talks to the engine — the
     * browser only ever sees Laravel.
     *
     * It also rescues a stalled run: if nothing has picked the job up (no worker was
     * running when it was queued) it is dispatched again. That is a cheap enqueue,
     * never an inline analysis, and `ProcessTakeoffRun` claims the row so a
     * duplicate worker is a no-op.
     */
    public function status(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $aiJob = $project->latestAiJob;

        if ($aiJob && $this->isStalled($aiJob)) {
            ProcessTakeoffRun::dispatch($aiJob->id);

            /*
             * `poll_attempts` always changes, which is what makes this write happen
             * at all: an update whose every value already matches is not dirty, so
             * Eloquent writes nothing and `updated_at` never moves — leaving the run
             * permanently "stalled" and queueing another copy on every single poll.
             */
            $aiJob->update([
                'stage' => 'requeued',
                'stage_label' => 'Waiting For A Worker',
                'poll_attempts' => $aiJob->poll_attempts + 1,
            ]);
        }

        $aiJob?->refresh();

        // Hold the request open until something actually changes, rather than
        // answering "still 35%" twenty times over.
        $aiJob = $this->awaitChange($aiJob, (string) $request->query('since', ''));

        return response()->json($this->runState($aiJob));
    }

    /**
     * Waits for the run to move on, within a bounded window.
     *
     * The browser sends the signature it last saw; while the run still matches it,
     * this re-reads one indexed row every tick and returns as soon as the two
     * differ. A run that is already finished, or a first poll with no signature,
     * returns straight away.
     */
    private function awaitChange(?AiJob $aiJob, string $since): ?AiJob
    {
        $hold = $this->holdSeconds();

        if ($aiJob === null || $since === '' || $hold <= 0 || $aiJob->isFinished()) {
            return $aiJob;
        }

        $tick = max(100, (int) config('takeoff.polling.tick_ms')) * 1000;
        $deadline = microtime(true) + $hold;

        while ($this->signature($aiJob) === $since && microtime(true) < $deadline) {
            usleep($tick);

            /*
             * Only the columns the signature is built from. A held request must stay
             * cheaper than the polls it replaces, so this is one primary-key select
             * per tick rather than a full model refresh with its relations.
             */
            $fresh = AiJob::query()
                ->select(['id', 'status', 'progress', 'stage'])
                ->find($aiJob->id);

            if ($fresh === null) {
                break;
            }

            if ($this->signature($fresh) !== $since) {
                return $aiJob->refresh();
            }
        }

        return $aiJob;
    }

    /**
     * How long a status request may wait, in seconds.
     *
     * Long polling costs a worker for the duration. The single-process built-in
     * server has exactly one, so holding a request there would freeze every other
     * request on the site — it answers immediately instead, whatever the config
     * says. `PHP_CLI_SERVER_WORKERS` makes the built-in server safe again.
     */
    private function holdSeconds(): int
    {
        $configured = (int) config('takeoff.polling.hold_seconds');

        if ($configured <= 0) {
            return 0;
        }

        $singleWorkerDevServer = PHP_SAPI === 'cli-server'
            && (int) getenv('PHP_CLI_SERVER_WORKERS') < 2;

        return $singleWorkerDevServer ? 0 : $configured;
    }

    /** The parts of a run a watching browser cares about, as one comparable string. */
    private function signature(AiJob $aiJob): string
    {
        return AiRunStatePresenter::signature($aiJob);
    }

    /** Queues the analysis again on request, for a run that never started. */
    public function retry(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        $aiJob = $project->latestAiJob;

        if (! $aiJob || $aiJob->isFinished()) {
            return back()->with('warning', 'There is no open run to start.');
        }

        // Asked for by hand, so the automatic rescue budget starts over.
        $aiJob->update(['poll_attempts' => 0]);

        ProcessTakeoffRun::dispatch($aiJob->id);

        return back()->with('success', 'The analysis was queued again.');
    }

    /** Abandons the run. */
    public function cancel(Request $request, Project $project, TakeoffOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('view', $project);

        $aiJob = $project->latestAiJob;

        if ($aiJob && ! $aiJob->isFinished()) {
            $orchestrator->cancel($aiJob);
        } else {
            $project->update(['status' => 'failed']);
            $project->uploads()->update(['status' => 'failed']);
        }

        return back()->with('warning', 'The takeoff run was cancelled.');
    }

    /**
     * Starts the first run for a project's drawing.
     *
     * The same shape as `restart()` below, for a project that has never been
     * analysed yet — the drawing is already on record (added on the Projects
     * screen), so nothing here asks the user to pick it again.
     */
    public function start(Request $request, Project $project, TakeoffOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('view', $project);

        if (! $orchestrator->configured()) {
            return back()->with('warning', 'The AI takeoff service is not configured.');
        }

        $upload = $project->takeoffDrawing();

        if (! $upload) {
            return back()->with('warning', 'Add a drawing PDF before running an AI takeoff.');
        }

        $existing = $project->latestAiJob;

        // Already running (or a double click just fired this twice) — send the
        // user to watch the run rather than starting a second one.
        if ($existing && ! $existing->isFinished()) {
            return redirect()->route('processing.show', $project);
        }

        $project->update([
            'status' => 'processing',
            'review_status' => 'none',
            'completed_at' => null,
            'started_at' => now(),
        ]);
        $project->uploads()->update(['status' => 'processing']);

        $aiJob = $orchestrator->open($project, $upload, $request->user());
        RenderDrawingPreviews::dispatch($upload->id);
        ProcessTakeoffRun::dispatch($aiJob->id);

        return redirect()->route('processing.show', $project)->with('success', 'The drawing was submitted for analysis.');
    }

    /** Submits the same drawing again as a fresh run. */
    public function restart(Request $request, Project $project, TakeoffOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('view', $project);

        if (! $orchestrator->configured()) {
            return back()->with('warning', 'The AI takeoff service is not configured.');
        }

        $upload = $project->takeoffDrawing();

        if (! $upload) {
            return back()->with('warning', 'The original drawing is no longer available.');
        }

        $project->update([
            'status' => 'processing',
            'review_status' => 'none',
            'completed_at' => null,
            'started_at' => now(),
        ]);
        $project->uploads()->update(['status' => 'processing']);

        $aiJob = $orchestrator->open($project, $upload, $request->user());
        RenderDrawingPreviews::dispatch($upload->id);
        ProcessTakeoffRun::dispatch($aiJob->id);

        return back()->with('success', 'The drawing was resubmitted for analysis.');
    }

    /**
     * True when a run is still sitting in the queue untouched.
     *
     * `queued` with no engine id means no worker has claimed it. Fifteen seconds is
     * long enough that a healthy worker will always have started first.
     */
    private function isStalled(AiJob $aiJob): bool
    {
        return $aiJob->status === AiJob::STATUS_QUEUED
            && blank($aiJob->external_id)
            && $aiJob->poll_attempts < self::MAX_RESCUE_ATTEMPTS
            && $aiJob->updated_at->lt(now()->subSeconds(15));
    }

    /**
     * Current state of the run, in the shape the processing screen consumes
     * — the same shape `AiTakeoffStatusChanged` broadcasts, so a polled
     * response and a pushed event are interchangeable to the frontend.
     *
     * @return array<string, mixed>
     */
    private function runState(?AiJob $aiJob): array
    {
        return AiRunStatePresenter::present($aiJob);
    }
}
