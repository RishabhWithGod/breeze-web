<?php

namespace App\Http\Controllers;

use App\Http\Resources\SymbolOverlayResource;
use App\Models\AiResult;
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

        return Inertia::render('ThreeDView', [
            'result' => [
                'id' => $result->id,
                'projectName' => $result->project?->name,
                'drawingName' => $result->project?->drawing_name,
                'pageCount' => (int) $result->page_count,
                'isFinalised' => $result->isFinalised(),
                'reviewUrl' => route('reviews.show', $result),
            ],
            /*
             * The same resource the review overlay is given, unpaginated and
             * unfiltered — one row per category, each carrying every occurrence
             * with its own page, bbox, status and origin.
             */
            'overlaySymbols' => SymbolOverlayResource::collection(
                $result->reviews()->visible()->get()
            )->resolve(),
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
