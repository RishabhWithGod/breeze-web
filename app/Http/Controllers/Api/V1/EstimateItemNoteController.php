<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\EstimateItem;
use App\Models\EstimateItemComment;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One material line's own notes — the per-material analogue of
 * `JobTaskNoteController`, now that the mobile Materials screen composes a
 * note per line instead of one for the whole task. Authority is derived the
 * same way `EstimateItemController::setCompletion()` already does: through
 * the line's own task, since a line was never staffed or locked separately
 * from it.
 */
class EstimateItemNoteController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function index(Request $request, EstimateItem $item): JsonResponse
    {
        abort_unless($item->job_task_id !== null, 404, 'This line is not part of a task yet.');
        $task = $item->task;
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        $notes = $item->comments()->with('author:id,name')->get();

        return $this->ok([
            'notes' => $notes->map(fn (EstimateItemComment $note) => $this->present($note))->values(),
        ]);
    }

    public function store(Request $request, EstimateItem $item): JsonResponse
    {
        abort_unless($item->job_task_id !== null, 404, 'This line is not part of a task yet.');
        $task = $item->task;
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');
        // Same authority as checking this line off its own checklist — the
        // crew actually on the task, or a planner overseeing it.
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        if ($task->job?->isReadyForReview() && ! $this->policy->reopenTask($request->user(), $task)) {
            return $this->fail(
                'This job has been submitted for review — wait for your foreman to act on it.',
                409,
            );
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $note = $item->comments()->create([
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);
        $note->load('author:id,name');

        return $this->created($this->present($note), 'Note added.');
    }

    /** @return array<string, mixed> */
    private function present(EstimateItemComment $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'author' => $note->author?->name,
            'createdAt' => $note->created_at?->toISOString(),
        ];
    }
}
