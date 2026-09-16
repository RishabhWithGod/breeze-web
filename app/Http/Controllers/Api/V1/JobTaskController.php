<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobTaskResource;
use App\Models\EstimateItem;
use App\Models\Job;
use App\Models\JobTask;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\Scheduling\JobTaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->with(['assignments.member', 'foreman', 'supervisor', 'estimateItems'])
            ->orderBy('position')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        // A task's percentage only updates when a checklist line is actually
        // toggled — one that was seeded before this feature existed, or
        // whose checklist was never touched, would otherwise keep showing
        // a stale number forever.
        $tasks->getCollection()->each(fn (JobTask $task) => $this->workflow->reconcileChecklistProgress($task));

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

        $task->load(['assignments.member', 'dependencies.dependsOn', 'foreman', 'supervisor', 'estimateItems']);
        $this->workflow->reconcileChecklistProgress($task);

        return $this->ok((new JobTaskResource($task))->resolve($request));
    }

    public function complete(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');

        abort_unless($task->job?->hasStarted(), 422, 'Start the job before working on its tasks.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        abort_if(
            $this->crewLockedForReview($request, $task),
            409,
            'This job has been submitted for review — wait for your supervisor to act on it.',
        );

        if ($blocker = $this->checklistBlocking($task)) {
            return $this->fail($blocker, 422);
        }

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

        abort_unless($task->job?->hasStarted(), 422, 'Start the job before working on its tasks.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        abort_if(
            $this->crewLockedForReview($request, $task),
            409,
            'This job has been submitted for review — wait for your supervisor to act on it.',
        );

        $data = $request->validate([
            'completion_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $task = $this->workflow->updateProgress($task, $data);

        return $this->ok((new JobTaskResource($task))->resolve($request), 'Progress updated.');
    }

    /**
     * Sets a task's status directly, to any of the seven states — the same
     * override a planner has on web (`JobTaskController::update()`, web),
     * not the crew's own narrower complete()/updateProgress(). Gated by
     * `reopenTask()`, not `completeTask()`: a site supervisor can correct or
     * reopen any task they are named on (or that a job they own), the same
     * authority a project manager has, whether or not they are personally
     * assigned to do the work itself.
     */
    public function setStatus(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->policy->reopenTask($request->user(), $task), 403, 'You cannot change this task’s status.');

        abort_unless($task->job?->hasStarted(), 422, 'Start the job before working on its tasks.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');

        $data = $request->validate([
            'status' => ['required', Rule::in(JobTask::STATUSES)],
        ]);

        if ($data['status'] === JobTask::STATUS_COMPLETED && ($blocker = $this->checklistBlocking($task))) {
            return $this->fail($blocker, 422);
        }

        $task = $this->workflow->setStatus($task, $data['status']);

        return $this->ok((new JobTaskResource($task))->resolve($request), 'Status updated.');
    }

    /**
     * Once the crew has submitted a job for review, only whoever can act on
     * that review — a planner/supervisor, via `reopenTask()`, the same
     * authority `setStatus()` above already requires to reopen a task — may
     * keep touching it. Everyone else has to wait: otherwise the crew could
     * keep quietly changing a job a supervisor is mid-review on, out from
     * under them.
     */
    private function crewLockedForReview(Request $request, JobTask $task): bool
    {
        $job = $task->job;
        if ($job === null || ! $job->isReadyForReview()) {
            return false;
        }

        return ! $this->policy->reopenTask($request->user(), $task);
    }

    /**
     * A task with a checklist cannot be marked complete while any line on it
     * is still unchecked — the whole point of the per-line checklist is that
     * "done" means every real piece of work was actually done, not just that
     * someone tapped the task's own circle. A task with no lines at all
     * (nothing was ever grouped into it) has nothing to block on.
     *
     * Labor lines only — a material line was never something to "do"; it
     * rides along on the labor line that installs it
     * (`JobTaskSetupController::pairedMaterialLines()`) as inventory info
     * for the Materials screen, not a separate checklist tick.
     */
    private function checklistBlocking(JobTask $task): ?string
    {
        $task->loadMissing('estimateItems');

        $outstanding = $task->estimateItems
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->whereNull('completed_at')
            ->count();
        if ($outstanding === 0) {
            return null;
        }

        return "Check off every item in the checklist first — {$outstanding} "
            .str('item')->plural($outstanding)." still unchecked.";
    }
}
