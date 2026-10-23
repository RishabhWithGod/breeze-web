<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\ApprovalHistory;
use App\Models\BoqLine;
use App\Models\Circuit;
use App\Models\DrawingSheet;
use App\Models\EquipmentItem;
use App\Models\Estimate;
use App\Models\FinalSymbol;
use App\Models\Job;
use App\Models\PanelSchedule;
use App\Models\ClientAddress;
use App\Models\Project;
use App\Models\Team;
use App\Models\SymbolReview;
use App\Models\Upload;
use App\Models\WireSize;
use App\Services\Ai\ArtefactStore;
use App\Services\Clients\ClientDirectory;
use App\Services\Clients\JobSites;
use App\Services\Export\AnnotatedPdfWriter;
use App\Services\Clients\ProjectDirectory;
use App\Services\Takeoff\CompleteReview;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\EstimateMergeJobBuilder;
use App\Services\Takeoff\JobFactory;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\CompanyRule;
use App\Support\Ownership;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * AI Takeoffs, as the mobile app sees them — a `Project` (the model backing
 * Web's AI Takeoff / History screen, `TakeoffHistoryController::index`),
 * scoped to the signed-in user's own projects (`Project::scopeOwnedBy`,
 * same shape as `Estimate`/`Job`). Newest first by `created_at`, matching
 * web's own default `->latest()` sort exactly (web calls it "date-desc").
 *
 * Deliberately minimal, matching the mobile Jobs/Estimates endpoints' own
 * precedent: no server-side search/status/sort params (web has all three
 * for its Inertia table; the mobile list filters client-side instead).
 */
class TakeoffController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ArtefactStore $store) {}

    /**
     * The takeoff history — web's `TakeoffHistoryController::index`: only
     * takeoffs under way or done (a draft or failed project is not a takeoff
     * yet), with search, status / client / project filters and sort all
     * running in the database. Also returns the real options for the client
     * and project filters.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'processing', 'ready-for-review', 'completed', 'converted'])],
            'client' => ['nullable', 'string', 'max:160'],
            'project' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', Rule::in(['date-desc', 'date-asc', 'name-asc'])],
        ]);

        $status = $filters['status'] ?? 'all';
        $client = $filters['client'] ?? 'all';
        $project = $filters['project'] ?? 'all';
        $sort = $filters['sort'] ?? 'date-desc';

        $finished = fn () => Project::query()
            ->ownedBy($request->user())
            ->whereIn('status', ['processing', 'completed', 'converted']);

        $projects = $finished()
            ->withCount('sheets')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', function ($query) use ($status) {
                // Not a stored column — `review_status` read a different way.
                if ($status === 'ready-for-review') {
                    return $query->whereIn('review_status', ['pending', 'in-review']);
                }

                return $query->where('status', $status);
            })
            ->when($client !== 'all', fn ($query) => $query->where('client', $client))
            ->when($project !== 'all', fn ($query) => $query->whereKey((int) $project))
            ->tap(fn ($query) => match ($sort) {
                'date-asc' => $query->orderBy('created_at')->orderBy('id'),
                'name-asc' => $query->orderBy('name')->orderBy('id'),
                default => $query->orderByDesc('created_at')->orderByDesc('id'),
            })
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'takeoffs' => $projects->getCollection()->map(fn (Project $project) => [
                'id' => $project->id,
                'projectName' => $project->name,
                'clientName' => $project->client,
                'drawingFileName' => $project->drawing_name,
                'status' => $project->status,
                // Read off `status` and `review_status` together — a review
                // pending or in progress says more than the status once started.
                'reviewStatus' => match (true) {
                    $project->status === 'processing' => 'processing',
                    $project->status === 'failed' => 'failed',
                    $project->status === 'converted' => 'converted',
                    in_array($project->review_status, ['pending', 'in-review'], true) => 'ready-for-review',
                    $project->status === 'completed' => 'completed',
                    default => 'draft',
                },
                'format' => $project->takeoffDrawing()?->format,
                'pageCount' => $project->page_count,
                'uploadedAt' => ($project->completed_at ?? $project->created_at)?->toISOString(),
                'sheetCount' => $project->sheets_count,
                'symbolsDetected' => $project->items_count,
            ])->all(),
            'meta' => [
                'currentPage' => $projects->currentPage(),
                'lastPage' => $projects->lastPage(),
                'perPage' => $projects->perPage(),
                'total' => $projects->total(),
            ],
            // Real options for the filter dropdowns, not a guess at what exists.
            'clients' => $finished()->whereNotNull('client')->distinct()->orderBy('client')->pluck('client')->values(),
            'projectOptions' => $finished()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
        ]);
    }

    /** `DELETE /takeoffs/{project}` — soft delete, which is what makes Undo a real restore. */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $project->delete();

        return $this->ok(['id' => $project->id], "\"{$project->name}\" was deleted.");
    }

    /** `POST /takeoffs/{project}/restore` — Undo for the delete above. */
    public function restore(Request $request, int $project): JsonResponse
    {
        $trashed = Project::onlyTrashed()->findOrFail($project);

        abort_unless(Ownership::owns($request->user(), $trashed->user_id), 403);

        $trashed->restore();

        return $this->ok(['id' => $trashed->id], "\"{$trashed->name}\" was restored.");
    }

    /**
     * A single takeoff's full processing/review/final state — read-only
     * parity with what the web app's Processing / AI Review / Final
     * Takeoff / Drawing Details screens show (see those controllers +
     * `AiRunStatePresenter`), minus the interactive symbol-crop/bounding-
     * box editor (no per-occurrence images or drawing overlay on mobile —
     * that needs a canvas editor and image serving this pass isn't
     * building). Approve/reject *is* real here (`SymbolReviewController`
     * below) — everything else on this endpoint is a read.
     *
     * A project with no run yet (still `draft`) simply has no job/result,
     * so every section below comes back empty/null rather than guessed.
     */
    public function show(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        // Remembered here too, same as web's own `ProcessingController::
        // show()`/`AiReviewController::show()`/`FinalTakeoffController::
        // show()` all do — mobile's Processing/Review/Results/Final
        // screens all load through this same endpoint, so one write here
        // covers all of them.
        app(TakeoffFlow::class)->remember($project, $request->user());

        $project->load([
            'sheets',
            'latestAiJob',
            'latestAiResult.reviews.reviewer:id,name',
            'latestAiResult.wireSizes',
            'latestAiResult.boqLines',
        ]);
        $job = $project->latestAiJob;
        $result = $project->latestAiResult;
        $upload = $project->takeoffDrawing();

        $sheetForPage = $this->sheetLookup($project->sheets);

        // Web's own "Automatic pricing" (Drawing Details) figures — the
        // engine's raw estimate, before any human review. Only worth
        // showing once it actually priced something.
        $engineEstimate = $result?->ai_estimate ?? [];
        $estimateTotals = ($engineEstimate['grand_total'] ?? 0) > 0 ? [
            'subtotal' => (float) ($engineEstimate['subtotal'] ?? 0),
            'tax' => (float) ($engineEstimate['tax'] ?? 0),
            'taxRate' => (float) ($engineEstimate['tax_rate'] ?? 0),
            'grandTotal' => (float) ($engineEstimate['grand_total'] ?? 0),
            'currency' => $engineEstimate['currency'] ?? 'USD',
            'lineCount' => (int) ($engineEstimate['line_count'] ?? 0),
        ] : null;

        return $this->ok([
            'id' => $project->id,
            'projectName' => $project->name,
            'clientId' => $project->client_id,
            'clientName' => $project->client,
            'drawingFileName' => $project->drawing_name,
            'status' => $project->status,
            'uploadedAt' => ($project->completed_at ?? $project->created_at)?->toISOString(),
            'sheetCount' => $project->sheets->count(),
            'symbolsDetected' => $project->items_count,
            'sheets' => $project->sheets->map(fn (DrawingSheet $sheet) => [
                'code' => $sheet->code,
                'title' => $sheet->title,
                'pageCount' => $sheet->page_count,
            ])->all(),

            // --- Drawing facts (Drawing Details header strip) -----------
            // Available as soon as the file is uploaded/analysed —
            // independent of review/finalisation, matching web's own
            // `DrawingDetailsController::show()`.
            'pageCount' => $result?->page_count ?? $project->page_count,
            'fileSizeBytes' => $upload?->size_bytes,
            'fileFormat' => $upload?->format,
            'hasDrawing' => $upload !== null && $this->store->exists($upload->path),
            // The drawing stamped with every reviewed decision — only once one exists.
            'hasAnnotatedPdf' => $result !== null && $upload !== null && $this->store->exists($upload->annotated_path),
            'notes' => $project->notes,

            // The engine's own automatic read of the drawing — available
            // the moment analysis finishes, well before a human reviews or
            // finalises anything (same data web's Drawing Details screen
            // shows under Bill of Quantities / Wire Sizes / Automatic
            // Pricing). Kept separate from the *reviewed* data below
            // (`final`), which only exists once finalised.
            'engineBoq' => $result ? $this->engineBoqRows($result) : [],
            'wireSizes' => $result ? $this->wireSizeRows($result) : [],
            'estimateTotals' => $estimateTotals,

            // --- Processing / run state (Processing screen) ------------
            'progress' => $job ? $job->progress / 100 : ($result ? 1.0 : 0.0),
            'jobStatus' => $job?->status,
            'stage' => $job?->stage,
            'stageLabel' => $job?->stage_label,
            'error' => $job?->error_message,
            'submittedAt' => $job?->submitted_at?->toISOString(),
            'completedAt' => $job?->completed_at?->toISOString(),
            // The same config-driven 7-step checklist web's Processing
            // screen shows (`config('takeoff.stages')`), with status
            // derived from the job's own progress/status — not the
            // engine's internal 5-stage `pipeline_status` (that's
            // reported separately below as `pipelineStatus`).
            'stages' => $this->stageChecklist($job),

            // --- Result summary (AI Review / Drawing Details header) ---
            'modelVersion' => $result?->model_version,
            'overallConfidence' => $result?->overall_confidence === null
                ? null
                : (float) $result->overall_confidence,
            'detectionCount' => $result?->detection_count,
            'processingTime' => $result?->processing_time === null
                ? null
                : (float) $result->processing_time,
            'reviewStatus' => $result?->review_status,
            'isFinalised' => $result?->isFinalised() ?? false,
            'finalisedAt' => $result?->finalised_at?->toISOString(),
            'estimateId' => $result?->estimate_id,
            'jobId' => $result?->work_job_id,
            'pipelineStatus' => $result ? $result->pipelineStages() : [],
            'warnings' => $result?->warnings ?? [],

            // --- Per-detection review list (AI Review screen, text-only) ---
            'symbols' => $result ? $result->reviews->take(500)->map(fn (SymbolReview $review) => [
                'id' => $review->id,
                'label' => $review->name ?: $review->ai_name,
                'aiName' => $review->ai_name,
                'category' => $review->ai_category,
                'confidence' => (float) $review->confidence,
                'status' => $review->status,
                'sheetName' => $sheetForPage($review->page),
                'page' => $review->page,
                'reason' => $review->reason,
                'notes' => $review->notes,
                'aiCount' => $review->ai_count,
                'finalCount' => $review->final_count,
                'occurrencesCount' => is_array($review->occurrences) ? count($review->occurrences) : 0,
                'isKnown' => (bool) $review->is_known,
                'isModified' => $review->isModified(),
                'sources' => $review->sourceLabels(),
                'reviewedBy' => $review->reviewer?->name,
                'reviewedAt' => $review->reviewed_at?->toISOString(),
                'origin' => $review->origin,
                'mergedIntoId' => $review->merged_into_id,
            ])->values()->all() : [],

            // --- Final takeoff data (Final Takeoff / Drawing Details) ---
            // Only once signed off — same gate web's Final/Drawing-Details
            // screens use (`AiResultPolicy::convert`/an unfinalised result
            // has no final_symbols/boq rows to show yet).
            'final' => ($result && $result->isFinalised()) ? $this->finalData($result) : null,

            // --- Approval audit trail (last 20, newest first) ----------
            'history' => $result ? $result->history()
                ->with('actor:id,name')
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (ApprovalHistory $entry) => [
                    'action' => $entry->action,
                    'subject' => $entry->subject,
                    'description' => $entry->description,
                    'fromValue' => $entry->from_value,
                    'toValue' => $entry->to_value,
                    'actorName' => $entry->actor?->name,
                    'occurredAt' => $entry->created_at?->toISOString(),
                ])
                ->values()
                ->all() : [],
        ]);
    }

    /**
     * The drawing exactly as uploaded — mobile's counterpart to web's
     * `DrawingDetailsController::file()`, streamed inline the same way.
     * Auth here is the bearer token (`auth:sanctum`), not a cookie session,
     * but `Storage::response()` doesn't care either way.
     */
    public function pdf(Request $request, Project $project): StreamedResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $upload = $project->takeoffDrawing();
        abort_unless($this->store->exists($upload?->path), 404);

        return $this->store->disk()->response($upload->path, $upload->name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.addslashes($upload->name).'"',
        ]);
    }

    /**
     * `GET /takeoffs/{project}/annotated` — web's `finals.annotated`: the drawing
     * stamped with every reviewed decision, served inline. Written on first ask,
     * and again with `?refresh=1` after further review.
     */
    public function annotated(Request $request, Project $project, AnnotatedPdfWriter $writer): StreamedResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);
        $path = $result->upload?->annotated_path;

        if ($request->boolean('refresh') || ! $this->store->exists($path)) {
            $path = $writer->write($result);
        }

        return $this->store->disk()->response($path, 'annotated-'.str($project->name)->slug().'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="annotated-'.addslashes(str($project->name)->slug()).'.pdf"',
        ]);
    }

    /**
     * The config-driven checklist web's Processing screen animates
     * (`config('takeoff.stages')`), with each step's status derived from
     * the job's own progress/status rather than a live per-stage signal —
     * mobile has no equivalent of the browser's own progress-to-stage
     * animation, so this computes the same proportional mapping once,
     * server-side, for a single correct snapshot.
     *
     * @return list<array{id: string, label: string, description: string, status: string}>
     */
    private function stageChecklist(?AiJob $job): array
    {
        $stages = config('takeoff.stages', []);
        if ($stages === [] || $job === null) {
            return [];
        }

        $count = count($stages);
        $progress = max(0, min(100, $job->progress));
        $failed = $job->status === AiJob::STATUS_FAILED;
        $finished = in_array($job->status, [AiJob::STATUS_SUCCEEDED], true);

        return collect($stages)->values()->map(function (array $stage, int $i) use ($count, $progress, $failed, $finished) {
            $threshold = ($i + 1) / $count * 100;
            $priorThreshold = $i / $count * 100;

            $status = match (true) {
                $finished => 'complete',
                $progress >= $threshold => 'complete',
                $failed && $progress >= $priorThreshold => 'failed',
                $progress > $priorThreshold => 'active',
                default => 'pending',
            };

            return [
                'id' => $stage['id'],
                'label' => $stage['label'],
                'description' => $stage['description'],
                'status' => $status,
            ];
        })->all();
    }

    /**
     * Everything web's Final Takeoff / Drawing Details screens show once a
     * result is signed off — the reviewed bill of quantities, the engine's
     * own priced BOQ beside it, and every other table the engine read off
     * the drawing (wire sizes, panel schedules, equipment, circuits).
     * Field-for-field the same shape `FinalTakeoffController::show()`
     * sends web, so this is a direct port, not a redesign.
     */
    private function finalData(AiResult $result): array
    {
        $result->loadMissing(['finalSymbols', 'wireSizes', 'panelSchedules', 'equipment', 'circuits', 'boqLines']);
        $payload = $result->final_payload ?? [];

        return [
            'totals' => [
                'symbolTypes' => $result->finalSymbols->where('count', '>', 0)->count(),
                'items' => (int) $result->finalSymbols->where('count', '>', 0)->sum('count'),
                'approved' => data_get($payload, 'metadata.approved', 0),
                'rejected' => data_get($payload, 'metadata.rejected', 0),
                'modified' => data_get($payload, 'metadata.modified', 0),
                'aiItems' => data_get($payload, 'metadata.ai_item_total', 0),
                'laborHours' => data_get($payload, 'boq.totals.labor_hours', 0),
                'materialCost' => data_get($payload, 'boq.totals.material_cost', 0),
            ],
            'finalSymbols' => $result->finalSymbols
                ->where('count', '>', 0)
                ->map(fn (FinalSymbol $symbol) => [
                    'id' => $symbol->id,
                    'name' => $symbol->name,
                    'count' => $symbol->count,
                    'confidence' => (float) $symbol->confidence,
                    'sources' => $symbol->sourceLabels(),
                    'pages' => $symbol->pages ?? [],
                    'wasModified' => (bool) $symbol->was_modified,
                    'wasRenamed' => (bool) $symbol->was_renamed,
                ])
                ->values()
                ->all(),
            'boq' => [
                'lines' => data_get($payload, 'boq.lines', []),
                'materials' => data_get($payload, 'boq.materials', []),
            ],
            'engineBoq' => $this->engineBoqRows($result),
            'wireSizes' => $this->wireSizeRows($result),
            'panelSchedules' => $result->panelSchedules->map(fn (PanelSchedule $panel) => [
                'page' => $panel->page,
                'panelName' => $panel->panel_name,
                'rows' => $panel->rows ?? [],
                'rawHeaders' => $panel->raw_headers ?? [],
            ])->all(),
            'equipment' => $result->equipment->map(fn (EquipmentItem $item) => [
                'page' => $item->page,
                'tag' => $item->tag,
                'description' => $item->description,
                'rating' => $item->rating,
                'quantity' => $item->quantity,
            ])->all(),
            'circuits' => $result->circuits->map(fn (Circuit $circuit) => [
                'page' => $circuit->page,
                'number' => $circuit->number,
                'description' => $circuit->description,
                'breaker' => $circuit->breaker,
                'panel' => $circuit->panel,
            ])->all(),
        ];
    }

    /** @return list<array{item: string, description: ?string, quantity: float, unit: ?string, unitPrice: float, subtotal: float, matchedSymbol: ?string}> */
    private function engineBoqRows(AiResult $result): array
    {
        return $result->boqLines->map(fn (BoqLine $line) => [
            'item' => $line->item,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'unitPrice' => (float) $line->unit_price,
            'subtotal' => (float) $line->subtotal,
            'matchedSymbol' => $line->matched_symbol,
        ])->all();
    }

    /** @return list<array{page: ?int, size: string, context: ?string, count: int}> */
    private function wireSizeRows(AiResult $result): array
    {
        return $result->wireSizes->map(fn (WireSize $wire) => [
            'page' => $wire->page,
            'size' => $wire->size,
            'context' => $wire->context,
            'count' => $wire->count,
        ])->all();
    }

    /** Maps a raw page number onto the sheet it falls in, via each ordered sheet's own page count. */
    private function sheetLookup(Collection $sheets): \Closure
    {
        $ranges = [];
        $start = 1;
        foreach ($sheets as $sheet) {
            /** @var DrawingSheet $sheet */
            $end = $start + max($sheet->page_count ?? 1, 1) - 1;
            $ranges[] = ['from' => $start, 'to' => $end, 'name' => $sheet->code ?: $sheet->title];
            $start = $end + 1;
        }

        return function (?int $page) use ($ranges): ?string {
            if ($page === null) {
                return null;
            }
            foreach ($ranges as $range) {
                if ($page >= $range['from'] && $page <= $range['to']) {
                    return $range['name'];
                }
            }

            return null;
        };
    }

    /**
     * The drawing overlay's data — every visible detection's own bbox and
     * occurrences, plus each page's real pixel dimensions to place them
     * against. Mobile's counterpart to web's `SymbolOverlayResource`
     * collection + `AiResult::pageDimensions()`, both sent together since a
     * canvas cannot draw one without the other.
     */
    public function overlay(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);

        return $this->ok([
            'symbols' => $result->reviews()->visible()->get()->map(fn (SymbolReview $review) => [
                'id' => $review->id,
                'name' => $review->name,
                'page' => $review->page,
                'bbox' => $review->bbox,
                'status' => $review->status,
                'finalCount' => $review->final_count,
                'aiCount' => $review->ai_count,
                'origin' => $review->origin,
                'occurrences' => $review->occurrences,
            ])->values()->all(),
            'pageDimensions' => $result->pageDimensions(),
        ]);
    }

    /**
     * A rendered page preview — mobile's counterpart to web's
     * `AiReviewController::pagePreview()`. Rendered on demand, under the
     * same lock key, when the background job hasn't produced it yet.
     */
    public function page(Request $request, Project $project, int $page): StreamedResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);
        $upload = $result->upload ?? $project->takeoffDrawing();
        abort_unless($upload !== null, 404);

        $path = $upload->previewFor($page);

        if (! $this->store->exists($path)) {
            $path = $this->renderOnDemand($upload, $page);
        }

        abort_unless($this->store->exists($path), 404);

        return $this->store->disk()->response($path);
    }

    /** Same on-demand render + lock as `AiReviewController::renderOnDemand()`. */
    private function renderOnDemand(Upload $upload, int $page): ?string
    {
        $lock = Cache::lock("previews:upload:{$upload->id}", 120);

        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return $upload->refresh()->previewFor($page);
        }

        try {
            $fresh = $upload->refresh();

            if ($this->store->exists($fresh->previewFor($page))) {
                return $fresh->previewFor($page);
            }

            $this->store->renderPreviews($fresh);

            return $fresh->refresh()->previewFor($page);
        } finally {
            $lock->release();
        }
    }

    /**
     * Signs off the review — mobile's counterpart to web's
     * `AiReviewController::finalise()`. Builds final_response.json, then
     * raises the estimate straight away so the app can navigate to it,
     * exactly as the browser lands on the estimate after finalising.
     * Idempotent: finalising an already-finalised takeoff just confirms it.
     */
    public function finalise(Request $request, Project $project, CompleteReview $complete, EstimateBuilder $estimateBuilder): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);

        if ($result->isFinalised()) {
            $existing = $result->estimate;

            return $this->ok($this->estimatePayload($existing), 'This review was already signed off.');
        }

        try {
            $complete->handle($result, $request->user());
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->fail('The review could not be completed: '.$e->getMessage(), 500);
        }

        try {
            $estimate = $estimateBuilder->fromFinalJson($result, $request->user());

            return $this->ok(
                $this->estimatePayload($estimate),
                "Review signed off. Estimate {$estimate->number} was generated from it."
            );
        } catch (RuntimeException $e) {
            return $this->ok(['estimateId' => null], 'Review signed off. Create the job when you’re ready: '.$e->getMessage());
        }
    }

    /**
     * `estimateId`/`estimateNumber` plus, when this estimate is an
     * addendum, `kind`/`parentEstimateId`/`parentEstimateNumber` — so a
     * mobile client can navigate to the parent estimate directly, same as
     * web's own `AiReviewController::finalise()` redirect there.
     *
     * @return array<string, mixed>
     */
    private function estimatePayload(?Estimate $estimate): array
    {
        if ($estimate === null) {
            return ['estimateId' => null];
        }

        $parent = $estimate->kind === Estimate::KIND_ADDENDUM ? $estimate->parentEstimate : null;

        return [
            'estimateId' => $estimate->id,
            'estimateNumber' => $estimate->number,
            'kind' => $estimate->kind,
            'parentEstimateId' => $parent?->id,
            'parentEstimateNumber' => $parent?->number,
        ];
    }

    /** Re-opens a finalised takeoff for further review — mirrors web's `reopen()` exactly. */
    public function reopen(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);

        if (! $result->isFinalised()) {
            return $this->fail('This review has not been finalised yet.', 422);
        }

        $result->update([
            'review_status' => AiResult::REVIEW_IN_PROGRESS,
            'finalised_at' => null,
            'finalised_by' => null,
        ]);
        $project->update(['review_status' => AiResult::REVIEW_IN_PROGRESS]);
        $result->recordHistory('review_reopened', 'Review reopened for further changes');

        return $this->ok(null, 'Review reopened.');
    }

    /**
     * "Continue to Job" — mobile's counterpart to web's
     * `FinalTakeoffController::storeJob()` primary path (the
     * fold-in-other-estimates variant is web-only; nothing in this flow
     * needs it). Builds the job from final_response.json, then prices it
     * straight away — one action, exactly as web's does it.
     */
    /**
     * `GET /takeoffs/{project}/job-form` — web's `FinalSymbols` (the "Job" step)
     * props: the job as it stands once raised (so the form is the same form
     * before and after), the takeoff's own client/project with its sites, the
     * crews it can be handed to, and any addenda selected to fold in
     * (`?merge_estimates=1,2`).
     */
    public function jobForm(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);

        app(TakeoffFlow::class)->remember($project, $request->user());

        $mergeIds = Estimate::query()
            ->whereIn('id', $this->parseEstimateIds($request->input('merge_estimates')))
            ->whereIn('user_id', Ownership::userIds($request->user()))
            ->where('kind', '!=', Estimate::KIND_MERGED)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        // A merge starts a brand-new job, so the takeoff's own is not what is edited.
        $workJob = $mergeIds === [] ? $result->workJob()->with('addresses')->first() : null;
        $result->loadMissing('estimate');

        $option = app(ProjectDirectory::class)
            ->options($request->user())
            ->firstWhere('id', $project->id);

        return $this->ok([
            'result' => [
                'id' => $project->id,
                'projectName' => $project->name,
                'drawingName' => $project->drawing_name,
                'isFinalised' => $result->isFinalised(),
                'finalisedAt' => $result->finalised_at?->toISOString(),
                'workJobId' => $workJob?->id,
                'workJobName' => $workJob?->name,
                'job' => $workJob === null ? null : [
                    'name' => $workJob->name,
                    'addressIds' => $workJob->addresses->pluck('id')->map(fn ($id) => (string) $id)->all(),
                    'description' => $workJob->description,
                    'jobType' => $workJob->job_type,
                    'teamId' => $workJob->team_id === null ? null : (string) $workJob->team_id,
                    'startDate' => $workJob->start_date?->toDateString(),
                    'endDate' => $workJob->end_date?->toDateString(),
                ],
                'estimateId' => $result->estimate_id,
                'estimateNumber' => $result->estimate?->number,
            ],
            'project' => $option,
            'teams' => Team::orderBy('name')->get(['id', 'name'])
                ->map(fn ($team) => ['id' => (string) $team->id, 'name' => $team->name])->all(),
            'mergeEstimateIds' => $mergeIds,
        ]);
    }

    /** @return array<int> */
    private function parseEstimateIds(mixed $raw): array
    {
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);

        return collect($ids)->map(fn ($id) => (int) trim((string) $id))->filter()->unique()->values()->all();
    }

    public function storeJob(
        Request $request,
        Project $project,
        JobFactory $factory,
        EstimateBuilder $estimateBuilder,
        JobSites $sites,
        ClientDirectory $clients,
        EstimateMergeJobBuilder $mergeBuilder,
    ): JsonResponse {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $this->resultFor($project);

        if (! $result->isFinalised()) {
            return $this->fail('Please finish reviewing the drawing before creating a job.', 422);
        }

        // Addenda selected to fold in: a brand-new job from exactly those, as web does.
        $mergeIds = $this->parseEstimateIds($request->input('estimate_ids'));

        if ($mergeIds !== []) {
            return $this->storeMergedJob($request, $mergeIds, $mergeBuilder);
        }

        // Whether this client already has a site on file decides whether one must be picked.
        $candidateProjectId = (int) ($request->input('project_id') ?: $result->project_id);
        $candidateClientId = (int) Project::whereKey($candidateProjectId)->value('client_id');
        $clientHasSites = $candidateClientId !== 0
            && ClientAddress::where('client_id', $candidateClientId)->exists();

        $attributes = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'address_ids' => $clientHasSites
                ? ['nullable', 'array', 'max:1']
                : ['required', 'array', 'min:1', 'max:1'],
            'address_ids.*' => ['integer', 'distinct', 'exists:client_addresses,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            'team_id' => ['required', 'integer', CompanyRule::exists('teams')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'address_ids.required' => 'This client has no site on file yet — add one below.',
            'address_ids.min' => 'This client has no site on file yet — add one below.',
            'team_id.required' => 'Pick the crew this job is handed to',
            'start_date.required' => 'Pick the day this job starts',
            'end_date.required' => 'Pick the day this job is due to finish',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ]);

        $addressIds = $attributes['address_ids'] ?? [];
        unset($attributes['address_ids']);

        $projectId = (int) ($attributes['project_id'] ?? $result->project_id);
        $clientId = (int) Project::whereKey($projectId)->value('client_id');
        $attributes['client_id'] = $clientId ?: null;

        $addresses = $addressIds === [] ? collect() : $sites->resolve($clientId, $addressIds);

        $existed = $result->work_job_id !== null;
        $typed = array_filter($attributes, fn ($value) => filled($value));

        try {
            $job = $factory->fromFinalJson($result, $request->user(), $typed);
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        if ($existed && $typed !== []) {
            $job->update($clients->withClientSnapshot($typed, $request->user()));
        }

        if ($addresses->isNotEmpty()) {
            $sites->attach($job, $addresses);
        }

        try {
            $estimate = $estimateBuilder->fromFinalJson($result->refresh(), $request->user(), $job);

            return $this->created([
                'jobId' => $job->id,
                'jobName' => $job->name,
                'estimateId' => $estimate->id,
                'estimateNumber' => $estimate->number,
            ], "\"{$job->name}\" was created from the reviewed takeoff, priced as {$estimate->number}.");
        } catch (RuntimeException $e) {
            return $this->created([
                'jobId' => $job->id,
                'jobName' => $job->name,
                'estimateId' => null,
            ], "\"{$job->name}\" was created from the reviewed takeoff. The estimate could not be generated: {$e->getMessage()}");
        }
    }

    /** @param array<int> $estimateIds */
    private function storeMergedJob(Request $request, array $estimateIds, EstimateMergeJobBuilder $builder): JsonResponse
    {
        $userIds = Ownership::userIdList($request->user());

        $attributes = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'address_ids' => ['nullable', 'array', 'max:1'],
            'address_ids.*' => [
                'integer', 'distinct',
                Rule::exists('client_addresses', 'id')->where(
                    fn ($query) => $query->whereIn('client_id', fn ($sub) => $sub->select('id')->from('clients')->whereIn('user_id', $userIds))
                ),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'job_type' => ['nullable', Rule::in(Job::TYPES)],
            'team_id' => ['required', 'integer', CompanyRule::exists('teams')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ], [
            'name.required' => 'Job name is required',
            'team_id.required' => 'Pick the crew this job is handed to',
            'start_date.required' => 'Pick the day this job starts',
            'end_date.required' => 'Pick the day this job is due to finish',
            'end_date.after_or_equal' => 'End date must be on or after the start date',
        ]);

        $sources = Estimate::query()
            ->whereIn('id', $estimateIds)
            ->whereIn('user_id', $userIds)
            ->where('kind', '!=', Estimate::KIND_MERGED)
            ->with('items')
            ->get();

        if ($sources->count() !== count($estimateIds)) {
            return $this->fail('One of the selected estimates could not be used — it may already be a merged estimate.', 422);
        }

        $job = $builder->build($sources, $attributes, $request->user());

        // A job raised from the selected estimates is where this takeoff's run
        // ends: it never attaches to the takeoff's own result, so without this
        // the Resume button would keep asking for a job that already exists.
        app(\App\Services\Takeoff\TakeoffFlow::class)->forget($request->user());

        return $this->created([
            'jobId' => $job->id,
            'jobName' => $job->name,
            'estimateId' => null,
        ], "\"{$job->name}\" was created from the selected estimates.");
    }

    /** The takeoff's own analysis result, or a 404 — every action below needs one to exist. */
    private function resultFor(Project $project): AiResult
    {
        $result = $project->latestAiResult;
        abort_unless($result !== null, 404, 'No analysis has been run for this project yet.');

        return $result;
    }
}
