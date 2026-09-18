<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\ApprenticeAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A manager's read/manage view of who is assigned as an apprentice on a
 * job — display, and removing an assignment gone wrong. The assignment
 * itself is a foreman's call, made from the mobile app's Job Detail screen
 * (`Api\V1\JobApprenticeAssignmentController`), not exposed here; both
 * controllers call the same {@see ApprenticeAssignmentService}.
 */
class JobApprenticeAssignmentController extends Controller
{
    public function __construct(
        private readonly JobSchedulePolicy $policy,
        private readonly ApprenticeAssignmentService $assignments,
    ) {}

    /** Removes an apprentice from the job. A manager correcting a foreman's assignment, not making one. */
    public function destroy(Request $request, Job $job, JobApprenticeAssignment $assignment): RedirectResponse
    {
        abort_unless($this->policy->assignApprentice($request->user(), $job), 403);
        abort_unless($assignment->job_id === $job->id, 404);
        $job->assertNotLocked();

        $name = $assignment->apprentice->name;
        $this->assignments->unassign($job, $assignment);

        return back()->with('warning', "{$name} removed from the job.");
    }
}
