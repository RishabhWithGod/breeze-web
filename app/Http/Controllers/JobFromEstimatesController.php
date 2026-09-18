<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobFromEstimatesRequest;
use App\Models\Estimate;
use App\Services\Takeoff\EstimateMergeJobBuilder;
use Illuminate\Http\RedirectResponse;

/**
 * Raises one new job from a standalone estimate plus whichever addenda were
 * selected on the Addendum screen — the multi-estimate half of the Addendum
 * feature (`AddendumController` is the read-only, single-estimate half).
 *
 * The actual build — new job, new merged estimate, cloned/deduped lines,
 * source estimates flipped to Approved — is `EstimateMergeJobBuilder`, shared
 * with `FinalTakeoffController::storeJob`'s own "Continue to Job" path so
 * there is exactly one implementation of "raise a job from selected sources".
 */
class JobFromEstimatesController extends Controller
{
    public function store(StoreJobFromEstimatesRequest $request, EstimateMergeJobBuilder $builder): RedirectResponse
    {
        $userId = $request->user()->id;
        $data = $request->validated();

        // Loaded and re-checked here rather than trusted from the request:
        // ownership was validated per-id, but "none of them is itself a
        // merge" needs the rows in hand.
        $sources = Estimate::query()
            ->whereIn('id', $data['estimate_ids'])
            ->where('user_id', $userId)
            ->where('kind', '!=', Estimate::KIND_MERGED)
            ->with('items')
            ->get();

        abort_unless(
            $sources->count() === count($data['estimate_ids']),
            422,
            'One of the selected estimates could not be used — it may already be a merged estimate.',
        );

        $job = $builder->build($sources, $data, $request->user());

        return redirect()
            ->route('jobs.tasks.setup', $job)
            ->with('success', "\"{$job->name}\" was created from the selected estimates.");
    }
}
