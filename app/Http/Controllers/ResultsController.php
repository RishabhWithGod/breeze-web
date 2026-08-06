<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectDetailResource;
use App\Models\AiResult;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entry point for "open the results of a run".
 *
 * An AI-analysed run is owned by the review workflow, so this routes to the
 * review screen while symbols are still being decided and to the final symbol
 * table once the takeoff has been signed off.
 */
class ResultsController extends Controller
{
    /** `/results` with no id opens the most recent analysed takeoff. */
    public function latest(Request $request): Response|RedirectResponse
    {
        $result = AiResult::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest('id')
            ->first();

        if ($result) {
            return $this->openReview($result);
        }

        $project = Project::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('symbols')
            ->latest('completed_at')
            ->first();

        return $project
            ? $this->show($request, $project)
            : redirect()->route('states.empty');
    }

    public function show(Request $request, Project $project): Response|RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $result = $project->latestAiResult;

        if ($result) {
            return $this->openReview($result);
        }

        // A run without symbols has nothing to review yet.
        if (! $project->hasResults()) {
            return redirect()->route('states.empty');
        }

        $project->load(['sheets', 'symbols', 'metrics', 'activities']);

        return Inertia::render('Results', [
            'project' => (new ProjectDetailResource($project))->resolve(),
        ]);
    }

    private function openReview(AiResult $result): RedirectResponse
    {
        return redirect()->route(
            $result->isFinalised() ? 'finals.show' : 'reviews.show',
            $result,
        );
    }
}
