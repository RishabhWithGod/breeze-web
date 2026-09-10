<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JobTask;
use App\Models\JobTaskComment;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A task's own notes — the same `job_task_comments` table and `JobTask`
 * relation the web app's Schedule screen writes to
 * (`JobTaskController::comment()`), just reachable from mobile now. Task-
 * scoped rather than job-scoped: the Materials screen groups everything by
 * task, and a note about one task's work belongs with that task, not lumped
 * into one undifferentiated pile for the whole job.
 */
class JobTaskNoteController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function index(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        // `JobTask::comments()` already orders oldest-first — a note thread
        // reads top to bottom like a conversation, not newest-on-top like an
        // activity feed.
        $notes = $task->comments()->with('author:id,name')->get();

        return $this->ok([
            'notes' => $notes->map(fn (JobTaskComment $note) => $this->present($note))->values(),
        ]);
    }

    public function store(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');
        // The same authority as checking off the task's own checklist — the
        // crew actually on it, or a planner overseeing it.
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        if ($task->job?->isReadyForReview() && ! $this->policy->updateTask($request->user(), $task)) {
            return $this->fail(
                'This job has been submitted for review — wait for your supervisor to act on it.',
                409,
            );
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $note = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);
        $note->load('author:id,name');

        return $this->created($this->present($note), 'Note added.');
    }

    /** @return array<string, mixed> */
    private function present(JobTaskComment $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->author?->name,
            'createdAt' => $note->created_at?->toISOString(),
        ];
    }
}
