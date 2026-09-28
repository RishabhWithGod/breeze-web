<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The takeoff a person is part-way through, so the mobile app can offer a
 * floating "Resume" button the same way web's own `ResumeTakeoffButton`
 * does — mobile's counterpart to `TakeoffFlow::current()`. Reads the same
 * per-account pointer (`users.takeoff_flow_project_id`) web's own screens
 * write via `TakeoffFlow::remember()`, so leaving the flow on web resumes
 * on mobile and back again — one pointer, read by both. Same stage logic
 * either way (`TakeoffFlow::stepForMobile()`, the exact conditions
 * `stepFor()` uses), translated to ids a mobile client navigates with
 * instead of a route URL.
 */
class TakeoffFlowController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly TakeoffFlow $flow) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $projectId = $user->takeoff_flow_project_id;

        if ($projectId === null) {
            return $this->ok(['takeoffFlow' => null]);
        }

        $project = Project::find($projectId);

        // The project was deleted. Nothing to return to.
        if ($project === null) {
            $this->flow->forget($user);

            return $this->ok(['takeoffFlow' => null]);
        }

        $step = $this->flow->stepForMobile($project);

        if ($step === null) {
            // Finished: the job exists and its work is laid out.
            $this->flow->forget($user);

            return $this->ok(['takeoffFlow' => null]);
        }

        return $this->ok([
            'takeoffFlow' => [
                'stage' => $step['stage'],
                'projectId' => $step['projectId'],
                'estimateId' => $step['estimateId'],
                'jobId' => $step['jobId'],
                'projectName' => $project->name,
            ],
        ]);
    }

    /** The person put the reminder away — same as web's own dismiss (X) button. */
    public function destroy(Request $request): JsonResponse
    {
        $this->flow->forget($request->user());

        return $this->ok();
    }
}
