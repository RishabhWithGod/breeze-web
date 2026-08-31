<?php

namespace App\Jobs;

use App\Models\AiResult;
use App\Services\Ai\LifecycleReader;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Files a run's crop images from the engine, in the background.
 *
 * Crops are decoration, but fetching them is one HTTP round trip *per symbol* — so
 * it must never happen while a page is rendering. The review screen serves only
 * what is already on our disk and asks for this job when something is missing; the
 * next view then has it.
 *
 * Unique per result, so a screen with twenty missing crops still only queues one
 * job.
 */
class BackfillTakeoffCrops implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public readonly int $aiResultId) {}

    public function uniqueId(): string
    {
        return (string) $this->aiResultId;
    }

    /** Long enough to cover a slow fetch, short enough to retry the same day. */
    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(LifecycleReader $lifecycle): void
    {
        $result = AiResult::find($this->aiResultId);

        if (! $result || ! $lifecycle->enabled()) {
            return;
        }

        try {
            $run = $result->run_id !== null
                ? ['run_id' => $result->run_id, 'lifecycle' => null]
                : $lifecycle->resolve($result->project_name ?: $result->project->name);

            if ($run === null) {
                return;
            }

            $lifecycle->attach($result, $run['lifecycle'] ?? $lifecycle->fetch($run['run_id']));
        } catch (Throwable $e) {
            Log::warning('Crop backfill failed', [
                'ai_result_id' => $this->aiResultId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
