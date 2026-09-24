<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTakeoffRun;
use App\Jobs\RenderDrawingPreviews;
use App\Models\AiJob;
use App\Models\Project;
use App\Services\Ai\AiRunStatePresenter;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live view of a run in flight — mobile's counterpart to web's own
 * `ProcessingController`. Same long-poll shape (`AiRunStatePresenter`,
 * the same `since` signature) so a mobile client and the browser watch the
 * exact same run state; same stalled-run rescue.
 */
class ProcessingController extends Controller
{
    use ApiResponses;

    private const MAX_RESCUE_ATTEMPTS = 8;

    public function status(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $aiJob = $project->latestAiJob;

        if ($aiJob && $this->isStalled($aiJob)) {
            ProcessTakeoffRun::dispatch($aiJob->id);

            $aiJob->update([
                'stage' => 'requeued',
                'stage_label' => 'Waiting For A Worker',
                'poll_attempts' => $aiJob->poll_attempts + 1,
            ]);
        }

        $aiJob?->refresh();
        $aiJob = $this->awaitChange($aiJob, (string) $request->query('since', ''));

        return $this->ok(AiRunStatePresenter::present($aiJob));
    }

    /** Waits for the run to move on, within a bounded window — same rules as web's own. */
    private function awaitChange(?AiJob $aiJob, string $since): ?AiJob
    {
        $hold = $this->holdSeconds();

        if ($aiJob === null || $since === '' || $hold <= 0 || $aiJob->isFinished()) {
            return $aiJob;
        }

        $tick = max(100, (int) config('takeoff.polling.tick_ms')) * 1000;
        $deadline = microtime(true) + $hold;

        while (AiRunStatePresenter::signature($aiJob) === $since && microtime(true) < $deadline) {
            usleep($tick);

            $fresh = AiJob::query()->select(['id', 'status', 'progress', 'stage'])->find($aiJob->id);

            if ($fresh === null) {
                break;
            }

            if (AiRunStatePresenter::signature($fresh) !== $since) {
                return $aiJob->refresh();
            }
        }

        return $aiJob;
    }

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

    /** Queues the analysis again on request, for a run that never started. */
    public function retry(Request $request, Project $project): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $aiJob = $project->latestAiJob;

        if (! $aiJob || $aiJob->isFinished()) {
            return $this->fail('There is no open run to start.', 422);
        }

        $aiJob->update(['poll_attempts' => 0]);
        ProcessTakeoffRun::dispatch($aiJob->id);

        return $this->ok(null, 'The analysis was queued again.');
    }

    /**
     * Submits the same drawing again as a fresh run — mobile's counterpart
     * to web's own `restart()`, for a run that genuinely failed (as
     * opposed to `retry()` above, which only rescues one stuck in the
     * queue with no worker).
     */
    public function restart(Request $request, Project $project, TakeoffOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        if (! $orchestrator->configured()) {
            return $this->fail('The AI takeoff service is not configured.', 503);
        }

        $upload = $project->takeoffDrawing();

        if (! $upload) {
            return $this->fail('The original drawing is no longer available.', 422);
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

        return $this->ok(['aiJobId' => $aiJob->id], 'The drawing was resubmitted for analysis.');
    }

    /** Abandons the run. */
    public function cancel(Request $request, Project $project, TakeoffOrchestrator $orchestrator): JsonResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $aiJob = $project->latestAiJob;

        if ($aiJob && ! $aiJob->isFinished()) {
            $orchestrator->cancel($aiJob);
        } else {
            $project->update(['status' => 'failed']);
            $project->uploads()->update(['status' => 'failed']);
        }

        return $this->ok(null, 'The takeoff run was cancelled.');
    }

    private function isStalled(AiJob $aiJob): bool
    {
        return $aiJob->status === AiJob::STATUS_QUEUED
            && blank($aiJob->external_id)
            && $aiJob->poll_attempts < self::MAX_RESCUE_ATTEMPTS
            && $aiJob->updated_at->lt(now()->subSeconds(15));
    }
}
