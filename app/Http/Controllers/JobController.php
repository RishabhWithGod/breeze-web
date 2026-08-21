<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobRequest;
use App\Http\Requests\UpdateJobRequest;
use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\FeedItemResource;
use App\Http\Resources\JobDetailResource;
use App\Http\Resources\JobResource;
use App\Models\Estimate;
use App\Models\FeedItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Services\Activity\FeedItemRecorder;
use App\Services\JobCosting\JobCostSummary;
use App\Services\TimeTracking\JobLaborSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function __construct(
        private readonly JobLaborSummary $laborSummary,
        private readonly JobCostSummary $costSummary,
        private readonly FeedItemRecorder $activity,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Job::STATUSES])],
            'foreman' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['all', ...Job::TYPES])],
            'sort' => ['nullable', Rule::in(Job::SORTS)],
            'view' => ['nullable', Rule::in(['active', 'archived', 'all'])],
            // Whether the filter drawer is expanded. Kept in the URL so it
            // survives the navigation each filter change triggers.
            'panel' => ['nullable', Rule::in(['open'])],
        ]);

        $status = $filters['status'] ?? 'all';
        $foreman = $filters['foreman'] ?? 'all';
        $type = $filters['type'] ?? 'all';
        $sort = $filters['sort'] ?? 'recent';
        $view = $filters['view'] ?? 'active';

        $jobs = Job::query()
            ->with('foreman')
            ->withCount(['teamMembers', 'estimates'])
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($type !== 'all', fn ($query) => $query->where('job_type', $type))
            ->when(
                $foreman !== 'all',
                fn ($query) => $query->whereHas('foreman', fn ($related) => $related->where('name', $foreman))
            )
            ->when($view === 'active', fn ($query) => $query->active())
            ->when($view === 'archived', fn ($query) => $query->archived())
            ->sorted($sort)
            ->paginate(config('takeoff.per_page'))
            ->withQueryString();

        return Inertia::render('Jobs', [
            'jobs' => JobResource::collection($jobs),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'foreman' => $foreman,
                'type' => $type,
                'sort' => $sort,
                'view' => $view,
                'panel' => $filters['panel'] ?? '',
            ],
            'foremen' => Foreman::orderBy('name')->get(['id', 'name', 'initials']),
            'counts' => [
                'active' => Job::query()->active()->count(),
                'archived' => Job::query()->archived()->count(),
            ],
            'activity' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::HISTORY_ACTIVITY)->get()
            )->resolve(),
        ]);
    }

    /** Full-page create form. */
    public function create(): Response
    {
        return Inertia::render('JobCreate', [
            'foremen' => Foreman::orderBy('name')->get(['id', 'name', 'initials']),
            'clients' => $this->knownClients(),
        ]);
    }

    public function store(StoreJobRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $isDraft = (bool) ($data['save_as_draft'] ?? false);
        unset($data['save_as_draft']);

        $job = Job::create([
            ...$data,
            'status' => $isDraft ? 'draft' : 'planning',
        ]);

        $job->recordInitialStatus();
        $job->recordActivity('created', $isDraft ? 'Job saved as a draft' : 'Job created');

        if (! $isDraft) {
            $this->activity->record(FeedItem::DASHBOARD_ACTIVITY, "New job created: {$job->name}", 'briefcase', 'lilac');
        }

        // "Create estimate for this job" — a real linked estimate, not a flag.
        if (! empty($data['create_estimate'])) {
            $estimate = $this->makeEstimateFor($job);
            $job->recordActivity(
                'estimate_created',
                "Estimate {$estimate->number} created",
                ['estimate_id' => $estimate->id, 'number' => $estimate->number],
            );
        }

        return redirect()
            ->route('jobs.show', $job)
            ->with(
                'success',
                $isDraft
                    ? "“{$job->name}” was saved as a draft."
                    : "“{$job->name}” was created."
            );
    }

    /** Job detail screen. */
    public function show(Request $request, Job $job): Response
    {
        $job->load([
            'foreman',
            'teamMembers',
            'estimates',
            'notes.author',
            'attachments.uploader',
            'activities.actor',
            'statusChanges.actor',
            'assignments.assigner',
            'aiResult',
        ]);

        $canViewTimeCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);
        $timeTracking = $this->laborSummary->for($job);
        $jobCosting = $this->costSummary->for($job);

        return Inertia::render('JobShow', [
            // `resolve()` strips the resource's `data` wrapper — Inertia props are
            // consumed directly by the page component.
            'job' => (new JobDetailResource($job))->resolve(),
            'assignableMembers' => TeamMember::query()
                ->whereNotIn('id', $job->teamMembers->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'name', 'initials', 'role']),
            // Assignments are role-based, so anyone can hold one — including
            // someone already on the crew list.
            'crew' => TeamMember::orderBy('name')->get(['id', 'name', 'initials', 'role']),
            // The takeoff's audit trail, so the job carries the reasoning behind
            // its numbers rather than just the numbers.
            'takeoffHistory' => $job->aiResult
                ? ApprovalHistoryResource::collection(
                    $job->aiResult->history()->with('actor')->take(25)->get()
                )->resolve()
                : [],
            // Live labor totals — never a stored duplicate of the time entries
            // they summarise. `laborCost`/`billableAmount` are dollar figures,
            // so they're nulled out below for a role without `viewJobCosts` —
            // the same rule the Job Costing dashboard applies.
            'timeTracking' => $canViewTimeCosts
                ? $timeTracking
                : [...$timeTracking, 'laborCost' => null, 'billableAmount' => null],
            'canViewTimeCosts' => $canViewTimeCosts,
            'jobCosting' => $canViewTimeCosts ? $jobCosting : JobCostSummary::redact($jobCosting),
            'documents' => $job->documents()->where('is_archived', false)->with('uploader')->take(5)->get()
                ->map(fn ($document) => (new DocumentResource($document))->resolve()),
            'documentsCount' => $job->documents()->where('is_archived', false)->count(),
        ]);
    }

    public function edit(Job $job): Response
    {
        return Inertia::render('JobEdit', [
            'job' => (new JobDetailResource($job->load('foreman', 'teamMembers')))->resolve(),
            'foremen' => Foreman::orderBy('name')->get(['id', 'name', 'initials']),
            'clients' => $this->knownClients(),
        ]);
    }

    public function update(UpdateJobRequest $request, Job $job): RedirectResponse
    {
        $data = $request->validated();
        $newStatus = $data['status'];
        unset($data['status']);

        $job->update($data);
        $job->recordActivity('updated', 'Job details updated');

        // Recorded separately so the status trail stays authoritative.
        $job->changeStatus($newStatus);

        return redirect()
            ->route('jobs.show', $job)
            ->with('success', "“{$job->name}” was updated.");
    }

    public function destroy(Job $job): RedirectResponse
    {
        $job->recordActivity('deleted', 'Job deleted');
        $job->delete();

        return redirect()
            ->route('jobs.index')
            ->with('warning', "“{$job->name}” was deleted.")
            // Lets the list offer Undo for a delete made on the detail screen.
            ->with('restore_job_id', $job->id);
    }

    /** Undo for the delete above. */
    public function restore(int $job): RedirectResponse
    {
        $trashed = Job::onlyTrashed()->findOrFail($job);
        $trashed->restore();
        $trashed->recordActivity('restored', 'Job restored');

        return back()->with('success', "“{$trashed->name}” was restored.");
    }

    public function archive(Job $job): RedirectResponse
    {
        $job->update(['archived_at' => now()]);
        $job->recordActivity('archived', 'Job archived');

        return back()->with('warning', "“{$job->name}” was archived.");
    }

    public function unarchive(Job $job): RedirectResponse
    {
        $job->update(['archived_at' => null]);
        $job->recordActivity('unarchived', 'Job restored from the archive');

        return back()->with('success', "“{$job->name}” was moved out of the archive.");
    }

    /** Copies the record, its team and its intake options into a new draft. */
    public function duplicate(Job $job): RedirectResponse
    {
        $copy = Job::create([
            'name' => "{$job->name} (Copy)",
            'client' => $job->client,
            'location' => $job->location,
            'description' => $job->description,
            'job_type' => $job->job_type,
            'status' => 'draft',
            'foreman_id' => $job->foreman_id,
            'start_date' => $job->start_date,
            'end_date' => $job->end_date,
            'budget' => $job->budget,
            'create_estimate' => false,
            'assign_team' => $job->assign_team,
            'notify_client' => $job->notify_client,
        ]);

        $copy->teamMembers()->attach(
            $job->teamMembers->mapWithKeys(fn ($member) => [
                $member->id => ['role_on_job' => $member->pivot->role_on_job],
            ])->all()
        );

        $copy->recordInitialStatus();
        $copy->recordActivity('duplicated', "Duplicated from “{$job->name}”", [
            'source_job_id' => $job->id,
        ]);
        $job->recordActivity('duplicated', "Duplicated as “{$copy->name}”", [
            'copy_job_id' => $copy->id,
        ]);

        return redirect()
            ->route('jobs.show', $copy)
            ->with('success', "“{$job->name}” was duplicated.");
    }

    /** Status change from the detail screen's dropdown. */
    public function changeStatus(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Job::STATUSES)],
        ]);

        $job->changeStatus($validated['status']);

        return back()->with('success', "Status set to {$validated['status']}.");
    }

    /** Archive / unarchive / delete / set-status across a selection. */
    public function bulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:work_jobs,id'],
            'action' => ['required', Rule::in(['archive', 'unarchive', 'delete', 'status'])],
            'status' => ['nullable', Rule::in(Job::STATUSES), 'required_if:action,status'],
        ]);

        $jobs = Job::whereIn('id', $validated['ids'])->get();
        $count = $jobs->count();

        foreach ($jobs as $job) {
            match ($validated['action']) {
                'archive' => tap($job)->update(['archived_at' => now()])
                    ->recordActivity('archived', 'Job archived in a bulk action'),
                'unarchive' => tap($job)->update(['archived_at' => null])
                    ->recordActivity('unarchived', 'Job unarchived in a bulk action'),
                'status' => $job->changeStatus($validated['status']),
                'delete' => tap($job)->recordActivity('deleted', 'Job deleted in a bulk action')
                    ->delete(),
            };
        }

        $label = match ($validated['action']) {
            'archive' => 'archived',
            'unarchive' => 'moved out of the archive',
            'delete' => 'deleted',
            'status' => "set to {$validated['status']}",
        };

        return back()->with(
            $validated['action'] === 'delete' ? 'warning' : 'success',
            $count.' '.str('job')->plural($count)." {$label}."
        );
    }

    /** Distinct clients already on record, for the intake selects. */
    private function knownClients(): Collection
    {
        return Job::query()
            ->whereNotNull('client')
            ->distinct()
            ->orderBy('client')
            ->pluck('client');
    }

    /** Creates a linked estimate carrying the job's client and budget. */
    private function makeEstimateFor(Job $job): Estimate
    {
        return Estimate::create([
            'job_id' => $job->id,
            'number' => Estimate::nextNumber(),
            'client' => $job->client ?? 'Unassigned',
            'project' => $job->name,
            'issued_on' => now()->toDateString(),
            'amount' => $job->budget ?? 0,
            'status' => 'draft',
        ]);
    }
}
