<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobTaskResource;
use App\Models\Job;
use App\Models\JobTask;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Scheduling\JobTaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Task management for the mobile app — deliberately scoped to what a field
 * electrician actually does: see their tasks, mark one complete, report
 * progress on one still in flight. Planning actions (assign/reschedule/
 * delete/dependencies/comments) stay web-only — those are office/PM
 * decisions, not something Phase 9 asked mobile to carry.
 *
 * `complete()`/`updateProgress()` delegate to `JobTaskWorkflowService`,
 * the exact same class `JobTaskController::complete()` (web) calls — one
 * implementation of what "completing a task" does, reused rather than
 * reimplemented.
 */
class JobTaskController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
        private readonly JobTaskWorkflowService $workflow,
    ) {}

    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $tasks = $job->tasks()
            ->with(['assignments.member'])
            ->orderBy('position')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->ok([
            'tasks' => JobTaskResource::collection($tasks->getCollection())->resolve($request),
            'meta' => [
                'currentPage' => $tasks->currentPage(),
                'lastPage' => $tasks->lastPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    public function show(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        $task->load(['assignments.member', 'dependencies.dependsOn']);

        return $this->ok((new JobTaskResource($task))->resolve($request));
    }

    public function complete(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');

        $data = $request->validate([
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        ['task' => $task, 'unblocked' => $unblocked] = $this->workflow->complete($task, $request->user(), $data);

        return $this->ok([
            'task' => (new JobTaskResource($task))->resolve($request),
            'unblockedCount' => $unblocked,
        ], 'Task completed.');
    }

    public function updateProgress(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');

        $data = $request->validate([
            'completion_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $task = $this->workflow->updateProgress($task, $data);

        return $this->ok((new JobTaskResource($task))->resolve($request), 'Progress updated.');
    }
}
