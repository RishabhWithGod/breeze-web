<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JobTask;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My Tasks" — every task on every job this user can access
 * (`ElectricianJobAccess::assignedJobsQuery`), flattened across jobs. No
 * assignee filter: the existing per-job endpoint
 * (`JobTaskController::index`) already returns every task on the one job
 * with no "assigned to me" narrowing, so this mirrors that exactly rather
 * than inventing a new filtering rule Breeze Web has no equivalent for —
 * there is no cross-job "My Tasks" page on web to match against
 * (`TaskListController`, web, is a company-wide planner view grouped by
 * job, not a per-user feed).
 *
 * Newest first (`created_at desc`), not `position` — `position` is a
 * manual per-job schedule order that isn't comparable once tasks from
 * different jobs are interleaved.
 */
class TaskController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tasks = JobTask::query()
            ->whereIn('job_id', $this->access->assignedJobsQuery($request->user())->select('id'))
            ->with(['job:id,name', 'foreman:id,name,initials', 'supervisor:id,name,initials', 'assignments.member'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        return $this->ok([
            'tasks' => $tasks->getCollection()->map(fn (JobTask $task) => [
                'id' => $task->id,
                'jobId' => $task->job_id,
                'jobName' => $task->job?->name ?? '',
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'assigneeName' => $this->assigneeName($task),
                'assigneeInitials' => $this->assigneeInitials($task),
                'dueDate' => $task->ends_on?->toDateString(),
                'createdAt' => $task->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'currentPage' => $tasks->currentPage(),
                'lastPage' => $tasks->lastPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    private function assigneeName(JobTask $task): ?string
    {
        return $task->foreman?->name
            ?? $task->supervisor?->name
            ?? $task->assignments->first()?->member?->name;
    }

    private function assigneeInitials(JobTask $task): ?string
    {
        return $task->foreman?->initials
            ?? $task->supervisor?->initials
            ?? $task->assignments->first()?->member?->initials;
    }
}
