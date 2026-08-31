<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TimeEntrySubmitted;
use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\TimeTracking\TimeEntryWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Time entries from the mobile app — the same `time_entries` table, the
 * same `TimeEntryWriteService` the web "Add Time Entry" form and the
 * "stop timer" flow both already use, and the same `TimeEntryPolicy` for
 * authorization. Edit/approve/reject stay web-only (a manager action, not
 * a field one); `submit` is included because logging time and turning it
 * in is the actual field workflow Phase 9 asked for.
 */
class TimeEntryController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TimeEntryWriteService $writer,
        private readonly ElectricianJobAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $entries = TimeEntry::query()
            ->with(['job', 'jobTask'])
            ->where('user_id', $request->user()->id)
            ->latest('date')->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'entries' => TimeEntryResource::collection($entries->getCollection())->resolve($request),
            'meta' => [
                'currentPage' => $entries->currentPage(),
                'lastPage' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    public function show(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);

        return $this->ok((new TimeEntryResource($entry->load(['job', 'jobTask'])))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:work_jobs,id'],
            'job_task_id' => ['nullable', 'integer', 'exists:job_tasks,id'],
            'task_label' => ['nullable', 'string', 'max:200'],
            'date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'description' => ['nullable', 'string', 'max:2000'],
            'billable' => ['nullable', 'boolean'],
        ]);

        $job = Job::findOrFail($data['job_id']);
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $entry = $this->writer->save($data, $request->user(), new TimeEntry);

        return $this->created((new TimeEntryResource($entry))->resolve($request), 'Time entry logged.');
    }

    public function submit(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('submit', $entry);

        $entry->changeStatus(TimeEntry::STATUS_SUBMITTED);
        $entry->update(['submitted_at' => now()]);
        $entry->recordActivity('submitted', 'Submitted for approval.');

        TimeEntrySubmitted::dispatch($entry);

        return $this->ok((new TimeEntryResource($entry))->resolve($request), 'Submitted for approval.');
    }
}
