<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JobTask;
use App\Models\SyncConflict;
use App\Notifications\SyncConflictEscalated;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Escalate" from the phone's Conflict Review: a field edit that clashed with the office's, handed up
 * for a manager to decide on the web instead of being settled in the field.
 */
class SyncConflictController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ElectricianJobAccess $access) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity' => ['required', 'in:task'],
            'entity_id' => ['required', 'integer'],
            'fields' => ['required', 'array', 'min:1', 'max:10'],
            'fields.*.key' => ['required', 'in:status,notes'],
            'fields.*.label' => ['required', 'string', 'max:40'],
            'fields.*.kind' => ['nullable', 'string', 'max:12'],
            'fields.*.field' => ['required', 'array'],
            'fields.*.office' => ['required', 'array'],
        ]);

        $task = JobTask::query()->with('job')->findOrFail($data['entity_id']);
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        // Sent again by the queue: one open conflict per task and person.
        $conflict = SyncConflict::query()
            ->where('entity_type', 'task')->where('entity_id', $task->id)
            ->where('user_id', $request->user()->id)->where('status', 'open')
            ->first();

        $conflict ??= SyncConflict::create([
            'job_id' => $task->job_id,
            'user_id' => $request->user()->id,
            'entity_type' => 'task',
            'entity_id' => $task->id,
            'title' => $task->title,
            'fields' => $data['fields'],
        ]);

        if ($conflict->wasRecentlyCreated && $task->job?->owner !== null) {
            $task->job->owner->notify(new SyncConflictEscalated($conflict, $request->user()->name));
        }

        return $this->created(['id' => $conflict->id], 'Sent to the office for review.');
    }
}
