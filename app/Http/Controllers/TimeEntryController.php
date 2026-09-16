<?php

namespace App\Http\Controllers;

use App\Events\TimeEntryApproved;
use App\Events\TimeEntryRejected;
use App\Events\TimeEntrySubmitted;
use App\Http\Resources\TimeEntryActivityResource;
use App\Http\Resources\TimeEntryResource;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\JobTask;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Policies\TimeEntryPolicy;
use App\Services\Export\TimesheetExporter;
use App\Services\TimeTracking\JobLaborSummary;
use App\Services\TimeTracking\TaskActualHoursRecalculator;
use App\Services\TimeTracking\TeamTimesheetBuilder;
use App\Services\TimeTracking\TimeEntryWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The time entries themselves: logging, editing, and the approval lifecycle.
 *
 * `store`/`update` share one job — decide the hours (from times, or as typed),
 * then hand the entry to `OvertimeCalculator`/`LaborCostCalculator` so the
 * split and the cost are never computed anywhere else. `submit`/`approve`/
 * `reject`/`reopen` are the workflow; each writes an activity row and a status
 * change, exactly as `Job`'s own audit trail does.
 */
class TimeEntryController extends Controller
{
    public function __construct(
        private readonly TaskActualHoursRecalculator $taskHours,
        private readonly TeamTimesheetBuilder $weekBuilder,
        private readonly TimesheetExporter $exporter,
        private readonly JobLaborSummary $laborSummary,
        private readonly TimeEntryWriteService $writer,
    ) {}

    public function index(Request $request): Response
    {
        [$entries, $filters, $canViewCrew] = $this->filtered($request);

        $weekAnchor = filled($request->query('week')) ? Carbon::parse($request->query('week')) : now();
        $weekSummary = $this->weekBuilder->build($weekAnchor, [
            'job' => $filters['job'] ?: null,
            'userId' => $canViewCrew ? null : $request->user()->id,
        ]);

        return Inertia::render('TimeEntries', [
            'entries' => TimeEntryResource::collection($entries),
            'filters' => $filters,
            'weekSummary' => $weekSummary,
            'jobs' => Job::query()->active()->orderBy('name')->get(['id', 'name', 'client', 'status']),
            'teamMembers' => TeamMember::orderBy('name')->get(['id', 'name', 'role']),
            'taskTypes' => JobTask::CATEGORIES,
            'attendance' => $this->attendanceFor($request, $filters, $canViewCrew),
            'can' => app(TimeEntryPolicy::class)->abilities($request->user()),
        ]);
    }

    /**
     * Job-site check-ins — GPS presence from the mobile app's own
     * check-in/check-out feature (`Api\V1\AttendanceController`), shown
     * alongside the logged/approved work-hour entries above but kept
     * distinct: no task, no approval workflow, just "was this technician
     * at the site, and for how long." Same crew-visibility rule as
     * {@see filtered()} — a foreman/electrician sees only their own, a
     * supervisor/manager sees everyone's.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function attendanceFor(Request $request, array $filters, bool $canViewCrew): array
    {
        // Unlike the time-entry list, which shows everything ever logged
        // until filtered, this section defaults to a recent window rather
        // than every day since the feature launched.
        $from = $filters['from'] ?: now()->subDays(14)->toDateString();
        $to = $filters['to'] ?: now()->toDateString();

        return JobAttendance::query()
            ->with(['job:id,name', 'user:id,name,role'])
            ->when(! $canViewCrew, fn ($q) => $q->where('user_id', $request->user()->id))
            ->when(! empty($filters['job']), fn ($q) => $q->where('job_id', $filters['job']))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->orderByDesc('date')
            ->orderByDesc('check_in_at')
            ->limit(100)
            ->get()
            ->map(fn (JobAttendance $row) => [
                'id' => $row->id,
                'date' => $row->date->toDateString(),
                'employee' => $row->user?->name ?? 'Unknown',
                'employeeRole' => $row->user?->role,
                'job' => $row->job ? ['id' => $row->job->id, 'name' => $row->job->name] : null,
                'status' => $row->status,
                'checkInAt' => $row->check_in_at?->toISOString(),
                'checkOutAt' => $row->check_out_at?->toISOString(),
                'checkInMethod' => $row->check_in_method,
                'checkOutMethod' => $row->check_out_method,
                'checkInDistanceMeters' => $row->check_in_distance_meters !== null
                    ? (float) $row->check_in_distance_meters
                    : null,
                'checkOutDistanceMeters' => $row->check_out_distance_meters !== null
                    ? (float) $row->check_out_distance_meters
                    : null,
                'hours' => round($row->workingSeconds() / 3600, 2),
                'photoUrl' => $row->check_in_photo_path
                    ? route('attendance.photo', $row->id)
                    : null,
            ])
            ->all();
    }

    /** The read-only "View" screen for one GPS check-in/check-out — every
     *  field {@see JobAttendance} carries, not just the list's summary
     *  columns. Same crew-visibility rule as {@see attendancePhoto()}. */
    public function showAttendance(Request $request, JobAttendance $attendance): Response
    {
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);
        abort_unless($canViewCrew || $attendance->user_id === $request->user()->id, 403);

        $attendance->load(['job', 'user']);
        $job = $attendance->job;

        return Inertia::render('AttendanceShow', [
            'attendance' => [
                'id' => $attendance->id,
                'date' => $attendance->date->toDateString(),
                'status' => $attendance->status,
                'employee' => ['name' => $attendance->user?->name ?? 'Unknown'],
                'job' => $job ? [
                    'id' => $job->id,
                    'name' => $job->name,
                    'client' => $job->client,
                    'status' => $job->status,
                ] : null,
                'hours' => round($attendance->workingSeconds() / 3600, 2),
                'bankedSeconds' => $attendance->banked_seconds,
                'checkIn' => [
                    'at' => $attendance->check_in_at?->toISOString(),
                    'method' => $attendance->check_in_method,
                    'accuracyMeters' => $attendance->check_in_accuracy !== null ? (float) $attendance->check_in_accuracy : null,
                    'distanceMeters' => $attendance->check_in_distance_meters !== null ? (float) $attendance->check_in_distance_meters : null,
                    'lat' => $attendance->check_in_lat !== null ? (float) $attendance->check_in_lat : null,
                    'lng' => $attendance->check_in_lng !== null ? (float) $attendance->check_in_lng : null,
                    'photoUrl' => $attendance->check_in_photo_path ? route('attendance.photo', $attendance->id) : null,
                ],
                'checkOut' => [
                    'at' => $attendance->check_out_at?->toISOString(),
                    'method' => $attendance->check_out_method,
                    'accuracyMeters' => $attendance->check_out_accuracy !== null ? (float) $attendance->check_out_accuracy : null,
                    'distanceMeters' => $attendance->check_out_distance_meters !== null ? (float) $attendance->check_out_distance_meters : null,
                    'lat' => $attendance->check_out_lat !== null ? (float) $attendance->check_out_lat : null,
                    'lng' => $attendance->check_out_lng !== null ? (float) $attendance->check_out_lng : null,
                ],
            ],
        ]);
    }

    /** The check-in selfie, when one exists — same crew-visibility rule as
     *  the list itself, not the general time-entry `view` policy (an
     *  attendance row has no task/job-ownership shape for that to key off). */
    public function attendancePhoto(Request $request, JobAttendance $attendance): StreamedResponse
    {
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);
        abort_unless($canViewCrew || $attendance->user_id === $request->user()->id, 403);
        abort_if($attendance->check_in_photo_path === null, 404);

        return ResponseFactory::streamDownload(
            fn () => print Storage::disk('local')->get($attendance->check_in_photo_path),
            "attendance-{$attendance->id}.jpg",
            ['Content-Type' => 'image/jpeg'],
            'inline',
        );
    }

    /** The same rows the screen lists, as a download. */
    public function export(Request $request, string $format): StreamedResponse|BinaryFileResponse
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);

        [$entries] = $this->filtered($request, paginate: false);

        $filename = 'time-entries-'.now()->toDateString();

        if ($format === 'csv') {
            $contents = $this->exporter->csv($entries);

            return ResponseFactory::streamDownload(
                fn () => print $contents,
                "{$filename}.csv",
                ['Content-Type' => 'text/csv'],
            );
        }

        return ResponseFactory::download($this->exporter->xlsx($entries), "{$filename}.xlsx")
            ->deleteFileAfterSend();
    }

    /**
     * The filtered query, shared by the list and the export so the two can
     * never show different rows for the same filters.
     *
     * @return array{0: mixed, 1: array<string, mixed>, 2: bool}
     */
    private function filtered(Request $request, bool $paginate = true): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'job' => ['nullable', 'integer'],
            'team_member' => ['nullable', 'integer'],
            'task_type' => ['nullable', Rule::in(['all', ...JobTask::CATEGORIES])],
            'status' => ['nullable', Rule::in(['all', ...TimeEntry::STATUSES])],
            'billable' => ['nullable', Rule::in(['all', 'yes', 'no'])],
            'sort' => ['nullable', 'string'],
        ]);

        $status = $filters['status'] ?? 'all';
        $billable = $filters['billable'] ?? 'all';
        $taskType = $filters['task_type'] ?? 'all';
        $canViewCrew = (bool) $request->user()->can('viewCrew', TimeEntry::class);

        $query = TimeEntry::query()
            ->with(['job', 'jobTask', 'user', 'teamMember'])
            ->when(! $canViewCrew, fn ($q) => $q->where('user_id', $request->user()->id))
            ->search($filters['search'] ?? null)
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('date', '>=', $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('date', '<=', $filters['to']))
            ->when(! empty($filters['job']), fn ($q) => $q->where('job_id', $filters['job']))
            ->when(! empty($filters['team_member']), fn ($q) => $q->where('team_member_id', $filters['team_member']))
            ->when($taskType !== 'all', fn ($q) => $q->whereHas('jobTask', fn ($t) => $t->where('category', $taskType)))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($billable !== 'all', fn ($q) => $q->where('billable', $billable === 'yes'))
            ->sorted($filters['sort'] ?? null);

        $entries = $paginate
            ? $query->paginate(config('time_tracking.per_page'))->withQueryString()
            : $query->get();

        return [
            $entries,
            [
                'search' => $filters['search'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'job' => $filters['job'] ?? '',
                'team_member' => $filters['team_member'] ?? '',
                'task_type' => $taskType,
                'status' => $status,
                'billable' => $billable,
            ],
            $canViewCrew,
        ];
    }

    /** The full-page "Add Time Entry" form. */
    public function create(Request $request): Response
    {
        return Inertia::render('TimeEntryForm', [
            'entry' => null,
            'jobs' => Job::query()->active()->orderBy('name')->get(['id', 'name', 'client', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $entry = $this->save($request, new TimeEntry);

        return redirect()
            ->route('time-entries.show', $entry)
            ->with('success', "Logged {$entry->hours} hrs on \"{$entry->job->name}\".");
    }

    /** The read-only "View" screen — the entry as recorded, plus its full audit trail. */
    public function show(Request $request, TimeEntry $entry): Response
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

        return Inertia::render('TimeEntryShow', [
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
            'jobTimeSummary' => $job ? $this->jobTimeSummaryFor($entry, $job, $teamMember) : null,
            'relatedEntries' => $job ? $this->relatedEntriesFor($entry, $job) : [],
            'can' => [
                'update' => (bool) $user->can('update', $entry),
                'delete' => (bool) $user->can('delete', $entry),
                'submit' => (bool) $user->can('submit', $entry),
                'approve' => (bool) $user->can('approve', $entry),
                'reject' => (bool) $user->can('reject', $entry),
                'viewJobCosts' => $canViewCosts,
            ],
        ]);
    }

    /**
     * This entry set against the job's own approved total and this same
     * person's total on the job — the same `time_entries` rows `JobLaborSummary`
     * already aggregates job-wide, just narrowed to one person.
     *
     * @return array{thisEntryHours: float, approvedJobHours: float, employeeJobHours: float, employeeJobBillableHours: float}
     */
    private function jobTimeSummaryFor(TimeEntry $entry, Job $job, ?TeamMember $teamMember): array
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

    /**
     * The compact shape a "Correction" link needs — enough to identify and
     * jump to the other entry, not the whole resource.
     *
     * @return array{id: int, date: string, employee: string, hours: float, status: string}
     */
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

    /** A handful of the job's other entries, for cross-checking this one against. */
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

    /** The full-page "Edit Time Entry" form. */
    public function edit(Request $request, TimeEntry $entry): Response
    {
        $this->authorize('update', $entry);

        return Inertia::render('TimeEntryForm', [
            'entry' => (new TimeEntryResource($entry->load(['job', 'jobTask'])))->resolve($request),
            'jobs' => Job::query()->active()->orderBy('name')->get(['id', 'name', 'client', 'status']),
        ]);
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('update', $entry);

        $entry = $this->save($request, $entry);

        return redirect()
            ->route('time-entries.show', $entry)
            ->with('success', 'Time entry updated.');
    }

    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        $entry->delete();

        return back()->with('warning', 'Time entry deleted.');
    }

    public function submit(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('submit', $entry);

        $entry->changeStatus(TimeEntry::STATUS_SUBMITTED);
        $entry->update(['submitted_at' => now()]);
        $entry->recordActivity('submitted', 'Submitted for approval.');

        TimeEntrySubmitted::dispatch($entry);

        return back()->with('success', 'Submitted for approval.');
    }

    public function approve(Request $request, TimeEntry $entry): RedirectResponse
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

        return back()->with('success', 'Time entry approved.');
    }

    public function reject(Request $request, TimeEntry $entry): RedirectResponse
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

        return back()->with('warning', 'Time entry rejected.');
    }

    /**
     * Corrects an approved entry: locks the original and opens a fresh draft
     * that points back at it, rather than editing approved history in place.
     */
    public function reopen(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->authorize('reopen', $entry);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        DB::transaction(function () use ($entry, $data) {
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
        });

        return redirect()->route('time-entries.index')
            ->with('success', 'A correction entry was created — edit and resubmit it.');
    }

    /** Tasks for the job selector's cascading picker. */
    public function jobTasks(Job $job): JsonResponse
    {
        return response()->json(
            $job->tasks()
                ->orderBy('position')
                ->get(['id', 'title', 'estimated_hours', 'actual_hours', 'status'])
                ->map(fn (JobTask $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'estimatedHours' => $task->estimated_hours !== null ? (float) $task->estimated_hours : null,
                    'actualHours' => (float) $task->actual_hours,
                    'status' => $task->status,
                ]),
        );
    }

    /* ------------------------------------------------------------- internals */

    private function save(Request $request, TimeEntry $entry): TimeEntry
    {
        $isNew = ! $entry->exists;

        if ($isNew) {
            $this->authorize('create', TimeEntry::class);
        } else {
            $this->authorize('update', $entry);
        }

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

        return $this->writer->save($data, $request->user(), $entry);
    }
}
