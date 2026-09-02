<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\SymbolOverlayResource;
use App\Http\Resources\SymbolReviewResource;
use App\Jobs\BackfillTakeoffCrops;
use App\Models\AiResult;
use App\Models\SymbolReview;
use App\Models\Upload;
use App\Services\Ai\ArtefactStore;
use App\Services\Takeoff\CompleteReview;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\EstimatingComponents;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
    public function show(Request $request, AiResult $result, EstimatingComponents $components): Response
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
            ->visible()
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
            // Unpaginated and unfiltered by the grid's own search/status — the
            // drawing overlay always shows every occurrence on the active page.
            'overlaySymbols' => SymbolOverlayResource::collection(
                $result->reviews()->visible()->get()
            )->resolve(),
            'pageDimensions' => $result->pageDimensions(),
            'tally' => $result->reviewTally(),
            /*
             * What the estimate will need from this takeoff, and how much of it
             * this run already has — the same list the estimate screen shows,
             * so the reviewer sees it before signing off rather than after.
             */
            'estimating' => $components->for($result),
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
     * Signs off the review: builds final_response.json, the final symbol table and
     * the bill of quantities, in one transaction — then raises the estimate
     * straight away, so the reviewer lands on it rather than an empty summary.
     *
     * The job and any assignment are still deliberately left for later — raised
     * explicitly, with whatever details the reviewer fills in, from the review
     * summary screen the estimate's "Continue" button leads to.
     */
    public function finalise(
        Request $request,
        AiResult $result,
        CompleteReview $complete,
        EstimateBuilder $estimateBuilder,
    ): RedirectResponse {
        $this->authorize('view', $result);

        // A duplicate submit (double click, a resend after the tab lost focus)
        // lands here after the first request already finalised it. Send the
        // reviewer back to a normal page instead of the 403 the policy would
        // otherwise raise for a review that's no longer theirs to finalise.
        if ($result->isFinalised()) {
            return redirect()
                ->route('reviews.show', $result)
                ->with('success', 'This review was already signed off.');
        }

        $this->authorize('finalise', $result);

        try {
            $complete->handle($result, $request->user());
        } catch (RuntimeException $e) {
            // Nothing approved yet: the reviewer's to fix.
            return back()->with('warning', $e->getMessage());
        } catch (Throwable $e) {
            // Already logged with its exception class and location; the takeoff is
            // untouched because the transaction rolled back.
            return back()->with(
                'warning',
                'The review could not be completed: '.$e->getMessage()
            );
        }

        try {
            $estimate = $estimateBuilder->fromFinalJson($result, $request->user());

            return redirect()
                ->route('estimates.show', $estimate)
                ->with('success', "Review signed off. Estimate {$estimate->number} was generated from it.");
        } catch (RuntimeException) {
            // Nothing priceable (e.g. every approved symbol counted to zero) —
            // fall back to the review summary rather than blocking sign-off.
            return redirect()
                ->route('finals.show', $result)
                ->with('success', 'Review signed off. Create the job when you’re ready.');
        }
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

        $upload = $result->upload;
        abort_unless($upload !== null, 404);

        $path = $upload->previewFor($page);

        /*
         * Previews are rendered by a queued job, so on a worker that never ran —
         * or one that failed, or a file since cleaned off the disk — the review
         * screen had nothing to show and no way to recover: every retry asked
         * for the same missing file and got the same 404.
         *
         * So the request renders them itself when they are not there. It costs
         * about a second, once, and only in the case that was previously a dead
         * end; every request after it is served straight off the disk.
         */
        if (! $store->exists($path)) {
            $path = $this->renderOnDemand($upload, $page, $store);
        }

        abort_unless($store->exists($path), 404);

        return $store->disk()->response($path);
    }

    /**
     * Renders this upload's previews now, under a lock.
     *
     * `renderPreviews` clears the directory before writing, so two requests
     * racing — the browser asking for one page while someone clicks to another —
     * must not run it at the same time. Whoever waits re-reads rather than
     * rendering a second time.
     */
    private function renderOnDemand(Upload $upload, int $page, ArtefactStore $store): ?string
    {
        $lock = Cache::lock("previews:upload:{$upload->id}", 120);

        try {
            // Waits for a render already in flight rather than starting another.
            $lock->block(30);
        } catch (LockTimeoutException) {
            return $upload->refresh()->previewFor($page);
        }

        try {
            $fresh = $upload->refresh();

            // Rendered while this request was waiting for the lock.
            if ($store->exists($fresh->previewFor($page))) {
                return $fresh->previewFor($page);
            }

            $store->renderPreviews($fresh);

            return $fresh->refresh()->previewFor($page);
        } finally {
            $lock->release();
        }
    }
}
