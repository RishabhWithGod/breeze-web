<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TimeEntryApproved;
use App\Events\TimeEntryRejected;
use App\Events\TimeEntrySubmitted;
use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryActivityResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Policies\TimeEntryPolicy;
use App\Services\Mobile\ElectricianJobAccess;
use App\Services\TimeTracking\JobLaborSummary;
use App\Services\TimeTracking\TaskActualHoursRecalculator;
use App\Services\TimeTracking\TimeEntryWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Time entries from the mobile app — the same `time_entries` table, the
 * same `TimeEntryWriteService`/`TimeEntryPolicy`/resources web's own
 * `TimeEntryController` uses. Full parity with web's workflow: logging,
 * editing, submitting, and — for whoever `TimeEntryPolicy` lets approve on
 * mobile too — approving, rejecting and reopening. Day-grouped list rows
 * that also merge in `JobAttendance` (GPS check-in/out) are a separate
 * concept web's `TimeEntries.tsx` happens to co-display; this index stays a
 * flat, filtered `TimeEntry` list — the same shape `filtered()`/`export()`
 * already use on web — rather than pulling that unrelated table in.
 */
class TimeEntryController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TimeEntryWriteService $writer,
        private readonly ElectricianJobAccess $access,
        private readonly TaskActualHoursRecalculator $taskHours,
        private readonly JobLaborSummary $laborSummary,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewCrew = (bool) $user->can('viewCrew', TimeEntry::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'job_id' => ['nullable', 'integer'],
            'team_member_id' => ['nullable', 'integer'],
            'task_type' => ['nullable', Rule::in(JobTask::CATEGORIES)],
            'status' => ['nullable', Rule::in(TimeEntry::STATUSES)],
            'billable' => ['nullable', Rule::in(['yes', 'no'])],
            'sort' => ['nullable', 'string'],
        ]);

        $entries = TimeEntry::query()
            ->with(['job', 'jobTask', 'user', 'teamMember'])
            ->when(! $canViewCrew, fn ($q) => $q->where('user_id', $user->id))
            ->search($filters['search'] ?? null)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(! empty($filters['job_id']), fn ($q) => $q->where('job_id', $filters['job_id']))
            ->when(! empty($filters['team_member_id']), fn ($q) => $q->where('team_member_id', $filters['team_member_id']))
            ->when(! empty($filters['task_type']), fn ($q) => $q->whereHas('jobTask', fn ($t) => $t->where('category', $filters['task_type'])))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['billable']), fn ($q) => $q->where('billable', $filters['billable'] === 'yes'))
            ->sorted($filters['sort'] ?? null)
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'entries' => TimeEntryResource::collection($entries->getCollection())->resolve($request),
            'meta' => [
                'currentPage' => $entries->currentPage(),
                'lastPage' => $entries->lastPage(),
                'total' => $entries->total(),
            ],
            // So the filter UI can hide what it cannot do — same reasoning
            // web hands `can` to `TimeEntries.tsx` for.
            'can' => app(TimeEntryPolicy::class)->abilities($user),
        ]);
    }

    public function show(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('view', $entry);
        $user = $request->user();

        $entry->load([
            'job.foreman',
            'jobTask.members',
            'user',
            'teamMember.user',
            'corrects.teamMember',
            'corrects.user',
            'corrections.teamMember',
            'corrections.user',
        ]);

        $canViewCosts = (bool) $user->can('viewJobCosts', TimeEntry::class);
        $job = $entry->job;
        $task = $entry->jobTask;
        $teamMember = $entry->teamMember;

        return $this->ok([
            'entry' => (new TimeEntryResource($entry))->resolve($request),
            'activities' => TimeEntryActivityResource::collection(
                $entry->activities()->with('user')->get()
            )->resolve($request),
            'employee' => [
                'name' => $teamMember?->name ?? $entry->user?->name ?? 'Unknown',
                'initials' => $entry->user?->initials,
                'role' => $teamMember?->role ?? $entry->user?->role,
                'email' => $teamMember?->user?->email ?? $entry->user?->email,
                'billableRate' => $canViewCosts && $teamMember?->billable_rate !== null
                    ? (float) $teamMember->billable_rate : null,
                'costRate' => $canViewCosts && $teamMember?->cost_rate !== null
                    ? (float) $teamMember->cost_rate : null,
            ],
            'job' => $job ? [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'status' => $job->status,
                'location' => $job->location,
                'jobType' => $job->job_type,
                'foreman' => $job->foreman?->name,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
            ] : null,
            'task' => $task ? [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'category' => $task->category,
                'status' => $task->status,
                'priority' => $task->priority,
                'startsOn' => $task->starts_on?->toDateString(),
                'endsOn' => $task->ends_on?->toDateString(),
                'estimatedHours' => $task->estimated_hours !== null ? (float) $task->estimated_hours : null,
                'actualHours' => (float) $task->actual_hours,
                'assignees' => $task->members->map(fn (TeamMember $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'role' => $member->pivot->role ?? null,
                ])->all(),
            ] : null,
            'corrects' => $entry->corrects ? $this->correctionRef($entry->corrects) : null,
            'corrections' => $entry->corrections->map(
                fn (TimeEntry $correction) => $this->correctionRef($correction)
            )->all(),
            'jobTimeSummary' => $job ? $this->jobTimeSummaryFor($entry, $job) : null,
            'relatedEntries' => $job ? $this->relatedEntriesFor($entry, $job) : [],
            'can' => [
                'update' => (bool) $user->can('update', $entry),
                'delete' => (bool) $user->can('delete', $entry),
                'submit' => (bool) $user->can('submit', $entry),
                'approve' => (bool) $user->can('approve', $entry),
                'reject' => (bool) $user->can('reject', $entry),
                'reopen' => (bool) $user->can('reopen', $entry),
                'viewJobCosts' => $canViewCosts,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TimeEntry::class);

        $data = $this->validated($request);

        $job = Job::findOrFail($data['job_id']);
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $entry = $this->writer->save($data, $request->user(), new TimeEntry);

        return $this->created((new TimeEntryResource($entry))->resolve($request), 'Time entry logged.');
    }

    public function update(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('update', $entry);

        $data = $this->validated($request);

        $job = Job::findOrFail($data['job_id']);
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $entry = $this->writer->save($data, $request->user(), $entry);

        return $this->ok((new TimeEntryResource($entry))->resolve($request), 'Time entry updated.');
    }

    public function destroy(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('delete', $entry);

        $entry->delete();

        return $this->ok(message: 'Time entry deleted.');
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

    public function approve(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('approve', $entry);

        DB::transaction(function () use ($request, $entry) {
            $entry->changeStatus(TimeEntry::STATUS_APPROVED);
            $entry->update(['approved_at' => now(), 'approved_by' => $request->user()->id]);
            $entry->recordActivity('approved', "Approved by {$request->user()->name}.");

            if ($entry->job_task_id !== null) {
                $this->taskHours->refresh($entry->jobTask);
            }
        });

        TimeEntryApproved::dispatch($entry);

        return $this->ok((new TimeEntryResource($entry))->resolve($request), 'Time entry approved.');
    }

    public function reject(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('reject', $entry);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $entry->changeStatus(TimeEntry::STATUS_REJECTED);
        $entry->update([
            'rejected_at' => now(),
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $data['reason'],
        ]);
        $entry->recordActivity('rejected', "Rejected by {$request->user()->name}: {$data['reason']}");

        TimeEntryRejected::dispatch($entry);

        return $this->ok((new TimeEntryResource($entry))->resolve($request), 'Time entry rejected.');
    }

    public function reopen(Request $request, TimeEntry $entry): JsonResponse
    {
        $this->authorize('reopen', $entry);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $correction = DB::transaction(function () use ($entry, $data) {
            $entry->changeStatus(TimeEntry::STATUS_LOCKED);
            $entry->recordActivity('locked', "Superseded by a correction: {$data['reason']}");

            if ($entry->job_task_id !== null) {
                $this->taskHours->refresh($entry->jobTask);
            }

            $correction = $entry->replicate([
                'status', 'submitted_at', 'approved_at', 'approved_by',
                'rejected_at', 'rejected_by', 'rejection_reason',
                'created_at', 'updated_at',
            ]);
            $correction->status = TimeEntry::STATUS_DRAFT;
            $correction->corrects_id = $entry->id;
            $correction->save();

            $correction->recordInitialStatus();
            $correction->recordActivity('created', "Correction of entry #{$entry->id}: {$data['reason']}");

            return $correction;
        });

        return $this->ok(
            (new TimeEntryResource($correction))->resolve($request),
            'A correction entry was created — edit and resubmit it.',
        );
    }

    /* ------------------------------------------------------------- internals */

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
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
    }

    /** @return array{thisEntryHours: float, approvedJobHours: float, employeeJobHours: float, employeeJobBillableHours: float} */
    private function jobTimeSummaryFor(TimeEntry $entry, Job $job): array
    {
        $personQuery = TimeEntry::query()
            ->where('job_id', $job->id)
            ->where('status', TimeEntry::STATUS_APPROVED)
            ->where('user_id', $entry->user_id);

        return [
            'thisEntryHours' => (float) $entry->hours,
            'approvedJobHours' => $this->laborSummary->for($job)['actualHours'],
            'employeeJobHours' => round((float) $personQuery->clone()->sum('hours'), 2),
            'employeeJobBillableHours' => round(
                (float) $personQuery->clone()->where('billable', true)->sum('hours'), 2
            ),
        ];
    }

    /** @return array{id: int, date: string, employee: string, hours: float, status: string} */
    private function correctionRef(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'employee' => $entry->teamMember?->name ?? $entry->user?->name ?? 'Unknown',
            'hours' => (float) $entry->hours,
            'status' => $entry->status,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function relatedEntriesFor(TimeEntry $entry, Job $job): array
    {
        return TimeEntry::query()
            ->with(['user', 'teamMember', 'jobTask'])
            ->where('job_id', $job->id)
            ->where('id', '!=', $entry->id)
            ->latest('date')->latest('id')
            ->take(6)
            ->get()
            ->map(fn (TimeEntry $related) => [
                'id' => $related->id,
                'date' => $related->date->toDateString(),
                'employee' => $related->teamMember?->name ?? $related->user?->name ?? 'Unknown',
                'task' => $related->jobTask?->title ?? $related->task_label ?? 'General',
                'startTime' => $related->start_time,
                'endTime' => $related->end_time,
                'hours' => (float) $related->hours,
                'status' => $related->status,
            ])
            ->all();
    }
}
