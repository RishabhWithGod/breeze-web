<?php

namespace App\Http\Controllers;

use App\Models\JobTask;
use App\Models\SyncConflict;
use App\Services\ChangeOrders\ChangeOrderAccess;
use App\Services\Scheduling\JobTaskWorkflowService;
use App\Support\Ownership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The office's side of Conflict Review: changes a technician made in the field that clashed with an
 * office edit and were sent up. A manager keeps what the office has, or applies the field's version.
 */
class SyncConflictController extends Controller
{
    public function __construct(
        private readonly ChangeOrderAccess $access,
        private readonly JobTaskWorkflowService $workflow,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->isManager($request->user()), 403);

        $rows = SyncConflict::query()
            ->with(['job:id,name,user_id', 'reporter:id,name', 'resolver:id,name'])
            ->whereHas('job', fn ($q) => $q->ownedBy($request->user()))
            ->orderByRaw("status = 'open' desc")
            ->latest('id')
            ->limit(100)
            ->get();

        return Inertia::render('SyncConflicts', [
            'conflicts' => $rows->map(fn (SyncConflict $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'job' => $c->job?->name,
                'jobId' => $c->job_id,
                'reporter' => $c->reporter?->name,
                'fields' => $c->fields,
                'status' => $c->status,
                'resolution' => $c->resolution,
                'resolvedBy' => $c->resolver?->name,
                'resolvedAt' => $c->resolved_at?->toISOString(),
                'createdAt' => $c->created_at?->toISOString(),
            ])->all(),
        ]);
    }

    public function resolve(Request $request, SyncConflict $conflict): RedirectResponse
    {
        abort_unless($this->access->isManager($request->user()), 403);
        abort_unless($conflict->job !== null && Ownership::owns($request->user(), $conflict->job->user_id), 403);
        abort_unless($conflict->isOpen(), 409, 'That conflict has already been settled.');

        $data = $request->validate([
            'resolution' => ['required', Rule::in([SyncConflict::KEEP_OFFICE, SyncConflict::APPLY_FIELD])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['resolution'] === SyncConflict::APPLY_FIELD) {
            $task = JobTask::query()->findOrFail($conflict->entity_id);
            foreach ($conflict->fields as $field) {
                $value = $field['field']['value'] ?? null;
                match ($field['key']) {
                    'status' => $this->workflow->setStatus($task, (string) $value),
                    'notes' => $task->update(['notes' => (string) $value]),
                    default => null,
                };
                $task->refresh();
            }
        }

        $conflict->update([
            'status' => 'resolved',
            'resolution' => $data['resolution'],
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', $data['resolution'] === SyncConflict::APPLY_FIELD
            ? 'The field version was applied.'
            : 'Kept the office version.');
    }
}
