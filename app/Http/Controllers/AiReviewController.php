<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\SymbolOverlayResource;
use App\Http\Resources\SymbolReviewResource;
use App\Jobs\BackfillTakeoffCrops;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\SymbolReview;
use App\Models\Upload;
use App\Services\Ai\ArtefactStore;
use App\Services\StaticTakeoff\StaticTakeoffResolver;
use App\Services\Takeoff\CompleteReview;
use App\Services\Takeoff\EstimateBuilder;
use App\Services\Takeoff\EstimatingComponents;
use App\Services\Takeoff\TakeoffFlow;
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
    public function show(Request $request, AiResult $result, EstimatingComponents $components, StaticTakeoffResolver $static): Response
    {
        $this->authorize('view', $result);

        // Remembered so the flow can be left and picked up again.
        if ($result->project !== null) {
            app(TakeoffFlow::class)->remember($result->project);
        }

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
                'pageCount' => $this->visiblePageCount($result, $static),
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

            // An addendum's own page is not where it is worked from — its
            // upload was started from the original estimate's screen, and
            // that is where the new addendum should appear, not on a second,
            // separate page nobody asked to open.
            if ($estimate->kind === Estimate::KIND_ADDENDUM) {
                return redirect()
                    ->route('estimates.show', ['estimate' => $estimate->parent_estimate_id, 'flow' => 1])
                    ->with('success', "Review signed off. Addendum {$estimate->addendum_number} was generated from it.");
            }

            return redirect()
                ->route('estimates.show', ['estimate' => $estimate, 'flow' => 1])
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
            return $this->cacheable($store->disk()->response($review->crop_path));
        }

        if (filled($review->image_path) || filled($result->run_id)) {
            // Unique per run, so a page full of misses queues one job.
            BackfillTakeoffCrops::dispatch($result->id);
        }

        abort(404, 'The crop image has not been filed yet.');
    }

    /** Serves a rendered page preview. */
    public function pagePreview(AiResult $result, int $page, ArtefactStore $store, StaticTakeoffResolver $static): StreamedResponse
    {
        $this->authorize('view', $result);

        $upload = $result->upload;
        abort_unless($upload !== null, 404);

        // A drawing with a marked copy shows that on review, and only the
        // pages that copy has. Any other drawing (or a marked page that
        // cannot be rendered) falls through to the plain upload below.
        $markedPdf = $static->markedFor($upload);

        if ($markedPdf !== null) {
            abort_if($markedPdf['pages'] > 0 && $page > $markedPdf['pages'], 404);

            $marked = $this->markedPreview($upload, $page, $markedPdf['path'], $store);

            if ($marked !== null) {
                return $this->cacheable($store->disk()->response($marked));
            }
        }

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
            $rendered = count($upload->preview_paths ?? []);

            // Past the bulk render's page cap: render just this page, rather
            // than re-rendering the set and still not having it.
            $path = $rendered > 0 && $page > $rendered
                ? $store->renderSinglePage($upload, $store->absolutePath($upload->path), $page)
                : $this->renderOnDemand($upload, $page, $store);

            if (! $store->exists($path) && $page > (int) config('ai.storage.max_preview_pages')) {
                $path = $store->renderSinglePage($upload, $store->absolutePath($upload->path), $page);
            }
        }

        abort_unless($store->exists($path), 404);

        return $this->cacheable($store->disk()->response($path));
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

    /**
     * Lets the browser keep a rendered page or crop for an hour.
     *
     * These images are large (a page preview is commonly 1–3 MB) and are
     * requested on every visit to the review screen; with the default
     * `no-cache` header each visit re-downloaded all of them. They only change
     * when the previews are re-rendered, so an hour of private caching is safe.
     */
    private function cacheable(StreamedResponse|BinaryFileResponse $response): StreamedResponse|BinaryFileResponse
    {
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    /** A marked copy with fewer pages than the drawing limits the pages the review shows. */
    private function visiblePageCount(AiResult $result, StaticTakeoffResolver $static): int
    {
        $count = (int) $result->page_count;
        $marked = $result->upload ? $static->markedFor($result->upload) : null;

        return $marked !== null && $marked['pages'] > 0 ? min($count ?: $marked['pages'], $marked['pages']) : $count;
    }

    /**
     * Path of the marked preview for this page.
     *
     * Only the requested page is rendered here (about a second); the whole
     * set is rendered ahead of time by `RenderDrawingPreviews`, so a request
     * never sits through a 12-page bulk render while holding up the server.
     */
    private function markedPreview(Upload $upload, int $page, string $markedPdf, ArtefactStore $store): ?string
    {
        return $store->markedPreviews($upload)[$page - 1]
            ?? $store->renderSinglePage($upload, $markedPdf, $page, marked: true);
    }
}
