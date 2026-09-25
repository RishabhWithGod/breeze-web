<?php

namespace App\Services\Ai\Contracts;

/**
 * Whatever answers a takeoff analysis: the real AI engine
 * (`App\Services\Ai\AiTakeoffClient`) or the DB-backed static resolver
 * (`App\Services\StaticTakeoff\StaticTakeoffEngine`).
 *
 * `App\Services\Ai\TakeoffOrchestrator` depends on this contract rather than
 * a concrete client, so the two are interchangeable without any change to
 * ingest, review, finalise, estimate or job creation.
 */
interface TakeoffEngine
{
    /**
     * Analyses a drawing and returns the engine's `AnalysisResult` shape
     * verbatim: `project_name, run_id, pages, symbols, known_symbols,
     * unknown_symbols, rejected_symbols, needs_review, panel_schedules,
     * equipment, wire_sizes, circuits, boq, estimate, warnings,
     * processing_time, pipeline_status`.
     *
     * @return array<string, mixed>
     */
    public function analyse(string $absolutePath, string $fileName): array;

    /** @return array{ok: bool, detail: array<string, mixed>|null} */
    public function health(): array;

    public function isConfigured(): bool;
}
