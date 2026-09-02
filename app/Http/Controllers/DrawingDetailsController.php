<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApprovalHistoryResource;
use App\Models\Project;
use App\Services\Ai\ArtefactStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything the drawing itself holds: the PDF, and every detail the AI engine
 * read off it.
 *
 * Reached from the takeoff history ("View") and from an estimate, so an estimator
 * can check a number against the drawing without leaving for another screen.
 */
class DrawingDetailsController extends Controller
{
    public function show(Request $request, Project $project, ArtefactStore $store): Response
    {
        $this->authorize('view', $project);

        $upload = $project->takeoffDrawing();
        $result = $project->latestAiResult;

        $result?->load(['wireSizes', 'panelSchedules', 'equipment', 'circuits', 'boqLines', 'workJob', 'estimate']);

        return Inertia::render('DrawingDetails', [
            'drawing' => [
                'projectId' => $project->id,
                'projectName' => $project->name,
                'client' => $project->client,
                'drawingName' => $project->drawing_name,
                'status' => $project->status,
                'reviewStatus' => $project->review_status,
                'pageCount' => $result?->page_count ?? $project->page_count,
                'itemsCount' => $project->items_count,
                'confidence' => $project->overall_confidence,
                'notes' => $project->notes,
                'uploadedAt' => $upload?->created_at?->toISOString(),
                'completedAt' => $project->completed_at?->toISOString(),
                'sizeBytes' => $upload?->size_bytes,
                'format' => $upload?->format,
                // Served inline so the viewer can embed it; absent if the file is gone.
                'fileUrl' => $store->exists($upload?->path)
                    ? route('drawings.file', $project)
                    : null,
                'annotatedUrl' => $result && $store->exists($upload?->annotated_path)
                    ? route('finals.annotated', $result)
                    : null,
                'previewUrls' => collect($upload?->preview_paths ?? [])
                    ->keys()
                    ->map(fn (int $index) => $result
                        ? route('reviews.page', ['result' => $result->id, 'page' => $index + 1])
                        : null)
                    ->filter()
                    ->values(),
            ],

            // Null for a project that never reached the engine (or failed there).
            'engine' => $result === null ? null : [
                'resultId' => $result->id,
                'runId' => $result->run_id,
                'engineProjectName' => $result->project_name,
                'engineVersion' => $result->model_version,
                'processingTime' => $result->processing_time,
                'receivedAt' => $result->received_at?->toISOString(),
                'reviewStatus' => $result->review_status,
                'isFinalised' => $result->isFinalised(),
                'detectionCount' => $result->detection_count,
                'symbolCounts' => $result->symbol_counts ?? [],
                'pipelineStatus' => $result->pipelineStages(),
                'warnings' => $result->warnings ?? [],
                'lifecycleStatistics' => $result->lifecycle_statistics,
                'estimateTotals' => $result->ai_estimate ?? [],
                'workJobId' => $result->work_job_id,
                'workJobName' => $result->workJob?->name,
                'estimateId' => $result->estimate_id,
                'estimateNumber' => $result->estimate?->number,
                'originalJsonUrl' => route('reviews.original', $result),
                'finalJsonUrl' => $result->isFinalised()
                    ? route('finals.export', ['result' => $result->id, 'format' => 'json'])
                    : null,
            ],

            'boq' => $result?->boqLines->map(fn ($line) => [
                'item' => $line->item,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'unitPrice' => (float) $line->unit_price,
                'subtotal' => (float) $line->subtotal,
                'matchedSymbol' => $line->matched_symbol,
            ])->all() ?? [],

            'wireSizes' => $result?->wireSizes->map(fn ($wire) => [
                'page' => $wire->page,
                'size' => $wire->size,
                'context' => $wire->context,
                'count' => $wire->count,
            ])->all() ?? [],

            'panelSchedules' => $result?->panelSchedules->map(fn ($panel) => [
                'page' => $panel->page,
                'panelName' => $panel->panel_name,
                'rows' => $panel->rows ?? [],
                'rawHeaders' => $panel->raw_headers ?? [],
            ])->all() ?? [],

            'equipment' => $result?->equipment->map(fn ($item) => [
                'page' => $item->page,
                'tag' => $item->tag,
                'description' => $item->description,
                'rating' => $item->rating,
                'quantity' => $item->quantity,
            ])->all() ?? [],

            'circuits' => $result?->circuits->map(fn ($circuit) => [
                'page' => $circuit->page,
                'number' => $circuit->number,
                'description' => $circuit->description,
                'breaker' => $circuit->breaker,
                'panel' => $circuit->panel,
            ])->all() ?? [],

            'history' => $result
                ? ApprovalHistoryResource::collection(
                    $result->history()->with('actor')->take(20)->get()
                )->resolve()
                : [],
        ]);
    }

    /**
     * The uploaded PDF itself, streamed inline so the browser's viewer can render
     * it in place rather than downloading it.
     */
    public function file(Request $request, Project $project, ArtefactStore $store): StreamedResponse
    {
        $this->authorize('view', $project);

        $upload = $project->takeoffDrawing();
        abort_unless($store->exists($upload?->path), 404);

        return $store->disk()->response($upload->path, $upload->name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($upload->name).'"',
        ]);
    }
}
