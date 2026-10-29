<?php

namespace App\Http\Controllers;

use App\Http\Resources\SymbolOverlayResource;
use App\Models\AiResult;
use App\Services\Review\SymbolIconMatcher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The spatial view of a takeoff's drawing.
 *
 * A read-only door onto data the review screen already owns: the same page
 * images, the same occurrence coordinates, the same category names. Every edit
 * made in there posts to the review's own endpoints — this controller has no
 * write actions and deliberately never will, because a second way to approve a
 * symbol is a second answer to "what did the reviewer decide".
 *
 * Isolated on purpose. Deleting this file, its route and the `ThreeD*`
 * components removes the feature and touches nothing else.
 */
class ThreeDViewController extends Controller
{
    /**
     * A small room to explore without a drawing — the "Explore sample 3D" on the upload screen. It has
     * no takeoff behind it, so nothing here can touch one.
     */
    public function sample(): Response
    {
        return Inertia::render('ThreeDView', [
            'result' => null,
            'overlaySymbols' => [],
            'symbolImages' => (object) [],
            'pageDimensions' => (object) [],
            'tally' => null,
            'canEdit' => false,
        ]);
    }

    public function show(Request $request, AiResult $result): Response
    {
        $this->authorize('view', $result);

        $result->load(['project', 'upload']);

        /*
         * The engine's own page sizes, unmodified. A page it never reported is
         * absent from this map rather than defaulted — the viewer says so and
         * draws nothing, because a symbol placed against a guessed page size is
         * in the wrong place and looks exactly like one in the right place.
         */
        $pageDimensions = $result->pageDimensions();

        $rows = $result->reviews()->visible()->get();
        $icons = app(SymbolIconMatcher::class);
        $symbolImages = $rows->mapWithKeys(function ($row) use ($icons) {
            $crop = ($row->crop_path || $row->image_path)
                ? route('reviews.crop', ['result' => $row->ai_result_id, 'review' => $row->id])
                : $row->crop_url;

            return [$row->id => $crop ?: $icons->urlFor($row->name ?: $row->ai_name)];
        })->all();

        return Inertia::render('ThreeDView', [
            'result' => [
                'id' => $result->id,
                'projectName' => $result->project?->name,
                'drawingName' => $result->project?->drawing_name,
                'pageCount' => (int) $result->page_count,
                'isFinalised' => $result->isFinalised(),
                'reviewUrl' => route('reviews.show', $result),
                // The steps around it, so Back and Continue work as they do on the review screen.
                'projectId' => $result->project_id,
                'estimateId' => $result->estimate_id,
            ],
            // How much of the review is decided: the same counts the review screen's footer reads.
            'tally' => $result->reviewTally(),
            /*
             * The same resource the review overlay is given, unpaginated and
             * unfiltered — one row per category, each carrying every occurrence
             * with its own page, bbox, status and origin.
             */
            'overlaySymbols' => SymbolOverlayResource::collection($rows)->resolve(),
            /*
             * What each symbol looks like, as the 2D review shows it: its own crop from the drawing when
             * there is one, else the reference icon for that kind of symbol, else nothing. Keyed by the
             * review row, so the 3D symbol list and the symbols in the scene carry the same pictures.
             */
            'symbolImages' => $symbolImages,
            'pageDimensions' => $pageDimensions,
            // Every category on the drawing, so colours are resolved across the
            // whole set exactly as the review screen resolves them.
            'distinctNames' => $result->reviews()->reorder()->distinct()->orderBy('name')->pluck('name'),
            /*
             * A finalised takeoff is read-only until it is reopened, and so is
             * this view — the same rule the review screen follows, read from the
             * same policy rather than re-decided here.
             */
            'canEdit' => $request->user()->can('review', $result) && ! $result->isFinalised(),
        ]);
    }
}
