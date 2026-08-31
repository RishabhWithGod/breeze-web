<?php

namespace App\Services\Ai;

use App\Models\AiJob;

/**
 * The shape of "a run's current state," shared by the Processing screen's
 * long-poll endpoint and the `AiTakeoffStatusChanged` broadcast — one
 * definition, so a browser applying a pushed event and a browser applying a
 * polled response are always looking at the exact same fields.
 */
class AiRunStatePresenter
{
    /** @return array<string, mixed> */
    public static function present(?AiJob $aiJob): array
    {
        if (! $aiJob) {
            return [
                'status' => 'missing',
                'progress' => 0,
                'stage' => null,
                'stageLabel' => null,
                'error' => 'No analysis has been requested for this drawing yet.',
                'reviewUrl' => null,
                'finished' => true,
                'runId' => null,
                'processingTime' => null,
                'pipelineStatus' => [],
                'warnings' => [],
                'awaitingWorker' => false,
                'signature' => '',
            ];
        }

        $result = $aiJob->result;

        return [
            'id' => $aiJob->id,
            'status' => $aiJob->status,
            'progress' => $aiJob->progress,
            'stage' => $aiJob->stage,
            'stageLabel' => $aiJob->stage_label,
            'error' => $aiJob->error_message,
            'runId' => $result?->run_id,
            'submittedAt' => $aiJob->submitted_at?->toISOString(),
            'completedAt' => $aiJob->completed_at?->toISOString(),
            'finished' => $aiJob->isFinished(),
            'reviewUrl' => $result ? route('reviews.show', $result, absolute: false) : null,
            'awaitingWorker' => $aiJob->status === AiJob::STATUS_QUEUED
                && blank($aiJob->external_id)
                && $aiJob->created_at->lt(now()->subSeconds(10)),
            'signature' => self::signature($aiJob),
            'processingTime' => $result?->processing_time,
            'pipelineStatus' => $result?->pipelineStages() ?? [],
            'warnings' => $result?->warnings ?? [],
        ];
    }

    /** The parts of a run a watching browser cares about, as one comparable string. */
    public static function signature(AiJob $aiJob): string
    {
        return $aiJob->status.'|'.$aiJob->progress.'|'.$aiJob->stage;
    }
}
