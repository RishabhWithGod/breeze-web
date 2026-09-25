<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Services\Ai\AiApiException;
use App\Services\Ai\TakeoffOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyses one drawing on the AI engine.
 *
 * `POST /api/upload` is synchronous and can run for minutes on a large set, which
 * is exactly why this is a queued job: the upload request returns as soon as the
 * file is safe on disk, and the run's state lives on the `ai_jobs` row so the
 * processing screen shows real progress while this worker is mid-flight.
 *
 * Deliberately not `ShouldBeUnique`: the lock would also swallow a reviewer's
 * explicit "queue it again", reporting success while dropping the dispatch. Two
 * copies of a run are made harmless by the row claim below instead, and the
 * processing screen bounds how often it re-queues one.
 *
 * Only one of these runs at a time — see `middleware()`.
 */
class ProcessTakeoffRun implements ShouldQueue
{
    use Queueable;

    /** Longer than the client's analyse timeout, so the client fails first. */
    public int $timeout = 1200;

    /**
     * Attempts are spent waiting for the engine, not retrying a failure.
     *
     * `handle()` converts every failure into a failed run rather than letting it
     * escape, so nothing here is ever retried after it has actually started. What
     * does consume an attempt is the overlap middleware putting the job back while
     * another drawing is on the engine — so this is really "how long may a run
     * queue for", at `releaseAfter` seconds each.
     */
    public int $tries = 240;

    public function __construct(public readonly int $aiJobId) {}

    /**
     * One drawing on the engine at a time.
     *
     * The engine is a single uvicorn worker: a second analysis does not run in
     * parallel, it queues *inside* the engine — and while it waits there it also
     * blocks the cheap endpoints this pipeline needs, so `/api/health` and the
     * lifecycle reads start timing out. Measured on two concurrent runs, the second
     * analysis took 49s of wall clock for 25s of engine work, and the first run's
     * crop enrichment went from 27ms to 23s.
     *
     * Serialising here costs nothing — the work was already serial — and keeps every
     * other queue job free to run alongside.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        // Nothing external to take turns on when answering from a static
        // dataset — and on the `sync` connection a job that can't acquire
        // the lock has nowhere to be released back to.
        if (! config('ai.one_run_at_a_time', true) || config('static_takeoff.enabled')) {
            return [];
        }

        return [
            (new WithoutOverlapping('ai-engine-analysis'))
                ->releaseAfter(5)
                // Longer than a run can take, so a worker killed mid-analysis
                // cannot wedge the queue behind a lock nobody holds.
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(TakeoffOrchestrator $orchestrator): void
    {
        $aiJob = AiJob::find($this->aiJobId);

        if (! $aiJob || $aiJob->isFinished()) {
            return;
        }

        /*
         * Claim the run before doing anything expensive. The processing screen may
         * re-dispatch a run that looks stalled (no worker was up when it was
         * queued), so two workers can hold the same job — the claim makes the second
         * one a no-op rather than a second analysis.
         */
        $claimed = AiJob::where('id', $aiJob->id)
            ->whereIn('status', [AiJob::STATUS_QUEUED, AiJob::STATUS_UPLOADING])
            ->update([
                'status' => AiJob::STATUS_UPLOADING,
                'stage' => 'claimed',
                'stage_label' => 'Starting',
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $aiJob->refresh();

        try {
            $orchestrator->analyse($aiJob);
        } catch (AiApiException $e) {
            $orchestrator->fail($aiJob, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Takeoff run crashed', [
                'ai_job_id' => $this->aiJobId,
                'error' => $e->getMessage(),
            ]);

            $orchestrator->fail($aiJob, 'The takeoff run stopped unexpectedly: '.$e->getMessage());
        }
    }

    public function failed(?Throwable $e): void
    {
        $aiJob = AiJob::find($this->aiJobId);

        if ($aiJob && ! $aiJob->isFinished()) {
            $aiJob->markFailed($e?->getMessage() ?? 'The takeoff run failed.');
        }
    }
}
