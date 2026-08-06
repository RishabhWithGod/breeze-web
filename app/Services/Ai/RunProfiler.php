<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use Illuminate\Support\Facades\Log;

/**
 * Stage-by-stage timing for one takeoff run.
 *
 * A run crosses four systems — the browser's upload, the queue, the Python engine
 * and the database — and "it got slower" is unanswerable without knowing which one
 * grew. Every stage is timed here, kept on the `ai_jobs` row, and logged as one
 * structured line when the run ends.
 *
 * Timings are diagnostics, never behaviour: nothing here may fail a run, so the
 * recorder is deliberately dumb and total-safe.
 */
class RunProfiler
{
    /** @var array<string, float> Milliseconds per stage, in the order they ran. */
    private array $stages = [];

    private ?float $startedAt = null;

    public function start(): self
    {
        $this->startedAt = microtime(true);
        $this->stages = [];

        return $this;
    }

    /**
     * Times one stage and returns whatever the work returned.
     *
     * Closure-based so a stage cannot be left unclosed, and so a throwing stage
     * still records the time it burned before it failed.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function measure(string $stage, callable $work): mixed
    {
        $began = microtime(true);

        try {
            return $work();
        } finally {
            $this->record($stage, (microtime(true) - $began) * 1000);
        }
    }

    /** Adds to a stage rather than replacing it, so a repeated stage accumulates. */
    public function record(string $stage, float $milliseconds): void
    {
        $this->stages[$stage] = round(($this->stages[$stage] ?? 0) + $milliseconds, 1);
    }

    public function started(): bool
    {
        return $this->startedAt !== null;
    }

    public function elapsedMs(): float
    {
        return $this->startedAt === null
            ? 0.0
            : round((microtime(true) - $this->startedAt) * 1000, 1);
    }

    /**
     * The stage table, plus the total and the slice nothing accounted for.
     *
     * `unmeasured` is the honest part: when it is large, the profiler is missing a
     * stage rather than the pipeline being fast.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $total = $this->elapsedMs();
        $measured = array_sum($this->stages);

        return [
            'stages_ms' => $this->stages,
            'total_ms' => $total,
            'unmeasured_ms' => round(max(0, $total - $measured), 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * Files the run's timings on its own row and logs them once.
     *
     * Diagnostics must never cost a finished run, so a failure to persist them is
     * swallowed after being logged.
     */
    public function persist(AiJob $aiJob, array $extra = []): void
    {
        $timings = $this->toArray() + $extra;

        Log::info('Takeoff run timings', ['ai_job_id' => $aiJob->id] + $timings);

        try {
            $aiJob->forceFill(['timings' => $timings])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('Run timings could not be stored', [
                'ai_job_id' => $aiJob->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
