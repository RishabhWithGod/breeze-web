<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\EstimateItem;
use App\Models\JobTask;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\JobTaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checking off one line of a task's work — one step finer than the task's
 * own overall complete()/progress. Same authority as completing the task
 * itself (`JobSchedulePolicy::completeTask()`): the crew on it, or a
 * planner overseeing it — except reopening a line on an already-completed
 * task, which only a planner may do (see `setCompletion()`).
 */
class EstimateItemController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly JobSchedulePolicy $policy,
        private readonly JobTaskWorkflowService $workflow,
    ) {}

    public function setCompletion(Request $request, EstimateItem $item): JsonResponse
    {
        abort_unless($item->job_task_id !== null, 404, 'This line is not part of a task yet.');
        $task = $item->task;
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not on this task.');

        abort_unless($task->job?->hasStarted(), 422, 'Start the job before working on its tasks.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        // Once the crew has submitted for review, only whoever can act on
        // that review (the same `updateTask()` authority a reopen already
        // requires below) may keep checking things off — the foreman
        // doing the review needs this endpoint to stay open to THEM
        // specifically, to send a line back.
        if ($task->job?->isReadyForReview() && ! $this->policy->reopenTask($request->user(), $task)) {
            return $this->fail(
                'This job has been submitted for review — wait for your foreman to act on it.',
                409,
            );
        }

        $data = $request->validate([
            'completed' => ['required', 'boolean'],
        ]);

        // The task is only ever completed by every line being checked, so
        // once it is, unchecking one is un-declaring it done — a journeyman
        // or apprentice who checked it off cannot walk that back alone. A
        // foreman watching this task can, any time, the one exception being
        // a job that is already completed and locked — caught above, before
        // this point, for everyone including them.
        if (! $data['completed']
            && $task->status === JobTask::STATUS_COMPLETED
            && ! $this->policy->reopenTask($request->user(), $task)) {
            return $this->fail('Only a foreman can reopen a completed task’s checklist.', 403);
        }

        $item->completed_at = $data['completed'] ? ($item->completed_at ?? now()) : null;
        $item->save();

        // The task's own percentage/status is derived from the checklist now
        // — no separate manual step, and no separate request from the app.
        // Reaching 100% here completes the task itself, the same as an
        // explicit tap on its own circle.
        $task = $this->workflow->syncFromChecklist($task, $request->user());

        return $this->ok([
            'id' => $item->id,
            'isCompleted' => $item->isCompleted(),
            'completedAt' => $item->completed_at?->toISOString(),
            'task' => [
                'status' => $task->status,
                'completionPct' => $task->completion_pct,
            ],
        ], $data['completed'] ? 'Marked done.' : 'Marked not done.');
    }
}
