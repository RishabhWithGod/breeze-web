<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\SymbolReviewResource;
use App\Jobs\BackfillTakeoffCrops;
use App\Models\Project;
use App\Models\SymbolReview;
use App\Services\Ai\ArtefactStore;
use App\Services\StaticTakeoff\StaticTakeoffResolver;
use App\Services\Takeoff\EstimatingComponents;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The AI Review screen for the app — mobile's counterpart to web's own
 * `AiReviewController::show()`/`crop()`: the detections a page at a time
 * (24, as web), the tally that decides whether "Continue to Estimate" is
 * open, what the estimate will need from this takeoff, and each symbol's
 * crop image. Decisions themselves go through `SymbolReviewController`.
 */
class ReviewController extends Controller
{
    use ApiResponses;

    private const PER_PAGE = 24;

    public function show(Request $request, Project $project, EstimatingComponents $components, StaticTakeoffResolver $static): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $project->latestAiResult;
        abort_if($result === null, 404, 'This drawing has not been analysed yet.');

        app(TakeoffFlow::class)->remember($project, $request->user());

        $result->load(['project', 'upload', 'aiJob']);

        $reviews = $result->reviews()
            ->visible()
            ->with('reviewer')
            ->paginate(self::PER_PAGE);

        $upload = $result->upload;
        $marked = $upload ? $static->markedFor($upload) : null;
        $pageCount = (int) $result->page_count;
        $pageCount = $marked !== null && $marked['pages'] > 0 ? min($pageCount ?: $marked['pages'], $marked['pages']) : $pageCount;

        return $this->ok([
            'result' => [
                'id' => $result->id,
                'projectId' => $result->project_id,
                'projectName' => $result->project->name,
                'drawingName' => $result->project->drawing_name,
                'pageCount' => $pageCount,
                'detectionCount' => $result->detection_count,
                'isFinalised' => $result->isFinalised(),
                'reviewStatus' => $result->review_status,
                'estimateId' => $result->estimate_id,
                'workJobId' => $result->work_job_id,
            ],
            'symbols' => $reviews->getCollection()->map(function (SymbolReview $review) use ($project, $request) {
                $row = (new SymbolReviewResource($review))->toArray($request);
                // Crops are served through the mobile API, with the bearer token.
                $row['cropUrl'] = ($review->crop_path || $review->image_path)
                    ? "/takeoffs/{$project->id}/symbols/{$review->id}/crop"
                    : null;

                return $row;
            })->values()->all(),
            // The whole drawing's category names, so every card and the legend resolve one colour per category.
            'distinctNames' => $result->reviews()->reorder()->distinct()->orderBy('name')->pluck('name'),
            'tally' => $result->reviewTally(),
            'estimating' => $components->for($result),
            'meta' => [
                'currentPage' => $reviews->currentPage(),
                'lastPage' => $reviews->lastPage(),
                'perPage' => $reviews->perPage(),
                'total' => $reviews->total(),
                'from' => $reviews->firstItem() ?? 0,
                'to' => $reviews->lastItem() ?? 0,
            ],
        ]);
    }

    /**
     * A symbol's crop image — web's `AiReviewController::crop()`. Never calls the engine
     * itself: a missing crop asks a background job to file the run's images and answers
     * 404 for now, so a card shows its placeholder and the next view has the picture.
     */
    public function crop(Request $request, Project $project, SymbolReview $review, ArtefactStore $store): StreamedResponse|BinaryFileResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $result = $project->latestAiResult;
        abort_unless($result !== null && $review->ai_result_id === $result->id, 404);

        if ($store->exists($review->crop_path)) {
            $response = $store->disk()->response($review->crop_path);
            $response->headers->set('Cache-Control', 'private, max-age=3600');

            return $response;
        }

        if (filled($review->image_path) || filled($result->run_id)) {
            BackfillTakeoffCrops::dispatch($result->id);
        }

        abort(404, 'The crop image has not been filed yet.');
    }
}
