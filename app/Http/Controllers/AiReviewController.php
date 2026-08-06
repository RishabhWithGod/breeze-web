<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\SymbolReviewResource;
use App\Jobs\BackfillTakeoffCrops;
use App\Models\AiResult;
use App\Models\SymbolReview;
use App\Services\Ai\ArtefactStore;
use App\Services\Takeoff\CompleteReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The AI Review screen: every detection the model returned, with the controls
 * that decide what reaches the final JSON.
 *
 * Nothing here mutates the AI response — decisions are recorded on the
 * `symbol_reviews` rows and audited in `approval_histories`.
 */
class AiReviewController extends Controller
{
    public function show(Request $request, AiResult $result): Response
    {
        $this->authorize('view', $result);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([
                'all', ...SymbolReview::STATUSES, 'modified', 'known', 'unknown',
                'needs-review', 'ai-rejected',
            ])],
            'page_no' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['position', 'confidence-desc', 'confidence-asc', 'name-asc'])],
        ]);

        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'position';
        $pageNo = $filters['page_no'] ?? null;

        $result->load(['project', 'upload', 'aiJob']);

        $reviews = $result->reviews()
            ->status($status)
            ->search($filters['search'] ?? null)
            ->when($pageNo, fn ($query) => $query->where('page', $pageNo))
            ->when($sort === 'confidence-desc', fn ($query) => $query->reorder()->orderByDesc('confidence'))
            ->when($sort === 'confidence-asc', fn ($query) => $query->reorder()->orderBy('confidence'))
            ->when($sort === 'name-asc', fn ($query) => $query->reorder()->orderBy('name')->orderBy('position'))
            ->with('reviewer')
            ->paginate(24)
            ->withQueryString();

        return Inertia::render('AiReview', [
            'result' => [
                'id' => $result->id,
                'projectId' => $result->project_id,
                'projectName' => $result->project->name,
                'client' => $result->project->client,
                'drawingName' => $result->project->drawing_name,
                'modelVersion' => $result->model_version,
                'pageCount' => $result->page_count,
                'detectionCount' => $result->detection_count,
                'overallConfidence' => $result->overall_confidence,
                'reviewStatus' => $result->review_status,
                'isFinalised' => $result->isFinalised(),
                'receivedAt' => $result->received_at?->toISOString(),
                'finalisedAt' => $result->finalised_at?->toISOString(),
                'originalJsonUrl' => route('reviews.original', $result),
                'workJobId' => $result->work_job_id,
                'estimateId' => $result->estimate_id,
                // Straight from the engine's response.
                'runId' => $result->run_id,
                'engineProjectName' => $result->project_name,
                'processingTime' => $result->processing_time,
                'pipelineStatus' => $result->pipelineStages(),
                'warnings' => $result->warnings ?? [],
                'lifecycleStatistics' => $result->lifecycle_statistics,
            ],
            'symbols' => SymbolReviewResource::collection($reviews),
            'tally' => $result->reviewTally(),
            'pages' => $result->reviews()
                /*
                 * `reviews()` orders by position and id for the grid. Those columns
                 * are meaningless once the rows are collapsed to one per page, and
                 * MySQL rejects the query outright for selecting an ordering column
                 * it cannot aggregate, so the inherited ordering is dropped first.
                 */
                ->reorder()
                ->selectRaw('page, count(*) as total')
                ->groupBy('page')
                ->orderBy('page')
                ->get()
                ->map(fn ($row) => ['page' => (int) $row->page, 'total' => (int) $row->total]),
            // Same reason as the page tally: a DISTINCT on one column cannot carry
            // the relation's row ordering, because those columns are not selected.
            'distinctNames' => $result->reviews()->reorder()->distinct()->orderBy('name')->pluck('name'),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'sort' => $sort,
                'pageNo' => $pageNo,
            ],
            'history' => ApprovalHistoryResource::collection(
                $result->history()->with('actor')->take(30)->get()
            )->resolve(),
        ]);
    }

    /**
     * Signs off the review and completes the handoff.
     *
     * One action, one transaction: final_response.json, the final symbol table, the
     * bill of quantities, the job, its estimate, the reviewer assignment and the
     * audit trail. Landing on the job means the reviewer sees the thing their work
     * produced rather than another button.
     */
    public function finalise(Request $request, AiResult $result, CompleteReview $complete): RedirectResponse
    {
        $this->authorize('finalise', $result);

        try {
            $job = $complete->handle($result, $request->user());
        } catch (RuntimeException $e) {
            // Nothing approved yet, or nothing to price: the reviewer's to fix.
            return back()->with('warning', $e->getMessage());
        } catch (Throwable $e) {
            // Already logged with its exception class and location; the takeoff is
            // untouched because the transaction rolled back.
            return back()->with(
                'warning',
                'The review could not be completed, so nothing was created: '.$e->getMessage()
            );
        }

        $estimate = $result->fresh()->estimate;

        return redirect()
            ->route('jobs.show', $job)
            ->with('success', sprintf(
                'Review signed off. “%s” and estimate %s were created from final_response.json.',
                $job->name,
                $estimate?->number ?? 'n/a',
            ));
    }

    /** Re-opens a finalised takeoff for further review. */
    public function reopen(AiResult $result): RedirectResponse
    {
        $this->authorize('view', $result);

        if (! $result->isFinalised()) {
            return back();
        }

        $result->update([
            'review_status' => AiResult::REVIEW_IN_PROGRESS,
            'finalised_at' => null,
            'finalised_by' => null,
        ]);
        $result->project->update(['review_status' => AiResult::REVIEW_IN_PROGRESS]);
        $result->recordHistory('review_reopened', 'Review reopened for further changes');

        return redirect()
            ->route('reviews.show', $result)
            ->with('warning', 'Review reopened. Generate the final JSON again when you are done.');
    }

    /** The untouched AI response, for download. */
    public function original(AiResult $result): StreamedResponse
    {
        $this->authorize('view', $result);

        $payload = $result->original_payload;
        $name = "original_response-{$result->id}.json";

        return ResponseFactory::streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            $name,
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Serves a symbol crop from our own disk.
     *
     * Deliberately never calls the engine: a grid of two dozen cards would mean two
     * dozen round trips inside page rendering, serialised behind one PHP process.
     * A missing crop asks a background job to fetch the run's images and answers 404
     * for now — the card shows its placeholder and the next view has the image.
     */
    public function crop(
        AiResult $result,
        SymbolReview $review,
        ArtefactStore $store,
    ): StreamedResponse|BinaryFileResponse {
        $this->authorize('view', $result);
        abort_unless($review->ai_result_id === $result->id, 404);

        if ($store->exists($review->crop_path)) {
            return $store->disk()->response($review->crop_path);
        }

        if (filled($review->image_path) || filled($result->run_id)) {
            // Unique per run, so a page full of misses queues one job.
            BackfillTakeoffCrops::dispatch($result->id);
        }

        abort(404, 'The crop image has not been filed yet.');
    }

    /** Serves a rendered page preview. */
    public function pagePreview(AiResult $result, int $page, ArtefactStore $store): StreamedResponse
    {
        $this->authorize('view', $result);

        $path = $result->upload?->previewFor($page);
        abort_unless($store->exists($path), 404);

        return $store->disk()->response($path);
    }
}
