<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobRequest;
use App\Http\Requests\UpdateJobRequest;
use App\Http\Resources\FeedItemResource;
use App\Http\Resources\JobDetailResource;
use App\Http\Resources\JobResource;
use App\Models\Estimate;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\Upload;
use App\Models\User;
use App\Policies\JobSchedulePolicy;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Clients\ClientDirectory;
use App\Services\Clients\JobSites;
use App\Services\Clients\ProjectDirectory;
use App\Services\JobCosting\JobCostSummary;
use App\Services\Takeoff\TakeoffFlow;
use App\Services\Takeoff\TakeoffLinkOptions;
use App\Support\JobOrigin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function __construct(
        private readonly JobCostSummary $costSummary,
        private readonly FeedItemRecorder $activity,
        private readonly TakeoffLinkOptions $linkOptions,
        private readonly ClientDirectory $clients,
        private readonly JobSites $sites,
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
        /*
         * Everything, archived included. The list no longer offers archiving,
         * so defaulting to the active view would leave anything archived
         * earlier invisible with nothing on the screen to bring it back. The
         * row still carries its Archived badge, which is what explains why a
         * job is missing from the job pickers.
         */
        $view = $filters['view'] ?? 'all';

        $jobs = Job::query()
            ->ownedBy($request->user())
            ->with(['foreman', 'team:id,name', 'activeAssignments.assigner'])
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
            // No foreman column or filter on the list, so no options to offer.
            // `?foreman=` is still honoured for a deep link.
            'activity' => FeedItemResource::collection(
                FeedItem::scope(FeedItem::HISTORY_ACTIVITY)->visibleTo($request->user())->get()
            )->resolve(),
        ]);
    }

    /** Full-page create form. */
    public function create(Request $request): Response
    {
        return Inertia::render('JobCreate', [
            'clients' => $this->clients->options($request->user()),
            // Their projects, each carrying its sites and its default drawing.
            'projects' => app(ProjectDirectory::class)->options($request->user()),
            'uploads' => $this->linkOptions->uploads(),
            // The crews a job can be handed to. Once one is picked it narrows
            // who a task on the job can be given to.
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            // Raising a job by hand forks a takeoff mid-flow: its own job is
            // raised from its review summary, not here.
            'unfinishedTakeoff' => app(TakeoffFlow::class)->inProgress($request),
        ]);
    }

    public function store(StoreJobRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $isDraft = (bool) ($data['save_as_draft'] ?? false);
        unset($data['save_as_draft']);

        $upload = isset($data['upload_id']) ? Upload::find($data['upload_id']) : null;
        $aiResult = $upload?->latestAiResult;
        unset($data['upload_id']);

        // `client` is a snapshot of the picked client's name, never typed.
        $data = $this->clients->withClientSnapshot($data, $request->user());

        // Refused here rather than trusted: the ids must be this client's own.
        // Against the client's own book — a project and its job share a place.
        $addresses = $this->sites->resolve((int) $data['client_id'], $data['address_ids']);
        unset($data['address_ids']);

        // The selected PDF already carries an estimate — link it rather than
        // raising a second one from the checkbox below.
        $linkedEstimate = $aiResult?->estimate;

        $job = Job::create([
            ...$data,
            'ai_result_id' => $aiResult?->id,
            'status' => $isDraft ? 'draft' : 'planning',
            /*
             * Never typed on this form: a job's budget is the estimate raised
             * against its drawing, so it is read from there directly. Null
             * while that drawing has no estimate yet.
             */
            'budget' => $linkedEstimate?->amount,
        ]);

        $this->sites->attach($job, $addresses);

        $job->recordInitialStatus();
        $job->recordActivity('created', $isDraft ? 'Job saved as a draft' : 'Job created');

        if (! $isDraft) {
            $this->activity->record($request->user(), FeedItem::DASHBOARD_ACTIVITY, "New job created: {$job->name}", 'briefcase', 'lilac');
        }

        if ($linkedEstimate && $linkedEstimate->job_id === null) {
            $linkedEstimate->update([
                'job_id' => $job->id,
                // The job it was waiting for has arrived: no longer a draft.
                ...($linkedEstimate->status === 'draft'
                    ? ['status' => Estimate::STATUS_FOR_A_LIVE_JOB]
                    : []),
            ]);
            $job->recordActivity(
                'estimate_created',
                "Estimate {$linkedEstimate->number} linked from {$upload->label()}",
                ['estimate_id' => $linkedEstimate->id, 'number' => $linkedEstimate->number],
            );
        } elseif (! empty($data['create_estimate'])) {
            // "Create estimate for this job" — a real linked estimate, not a flag.
            $estimate = $this->makeEstimateFor($job, $request->user());
            $job->recordActivity(
                'estimate_created',
                "Estimate {$estimate->number} created",
                ['estimate_id' => $estimate->id, 'number' => $estimate->number],
            );
        }

        /*
         * Straight on to laying the job out in tasks. A draft is not ready to be
         * planned, so it opens on the job itself; anything else goes to the step
         * that turns a job into a plan, which can still be skipped there.
         */
        return redirect()
            ->route($isDraft ? 'jobs.show' : 'jobs.tasks.setup', $job)
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
        $this->authorize('view', $job);

        $job->load([
            'project',
            'team:id,name',
            'teamMembers',
            'estimates',
            // Ordered the way the schedule holds them, with the count of what
            // each covers — the detail the panel states without the lines.
            'tasks' => fn ($query) => $query
                ->with(
                    'foreman:id,name',
                    'supervisor:id,name',
                    // Field notes/photos — the crew's own Materials screen on
                    // the mobile app, read-only here.
                    'comments.author:id,name',
                    'attachments.uploader:id,name',
                )
                ->withCount('estimateItems')
                ->orderBy('position')
                ->orderBy('id'),
            'notes.author',
            'attachments.uploader',
            'activities.actor',
            'statusChanges.actor',
            'assignments.assigner',
            'aiResult',
        ]);

        $canViewTimeCosts = (bool) $request->user()->can('viewJobCosts', TimeEntry::class);
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
            'canViewTimeCosts' => $canViewTimeCosts,
            'jobCosting' => $canViewTimeCosts ? $jobCosting : JobCostSummary::redact($jobCosting),
            // Whoever plans the work may add to it; everyone else still reads.
            'canPlanWork' => app(JobSchedulePolicy::class)
                ->createTask($request->user(), $job->schedule ?? new JobSchedule(['job_id' => $job->id])),
            /** Where Back goes — see App\Support\JobOrigin. */
            'back' => JobOrigin::back($request->query('from')),
            // The same trail as a bare name, so the Edit link can carry it on
            // and the job someone returns to still knows the way out.
            'from' => $this->originName($request),
        ]);
    }

    public function edit(Request $request, Job $job): Response
    {
        $this->authorize('update', $job);

        return Inertia::render('JobEdit', [
            'job' => (new JobDetailResource($job->load('teamMembers', 'addresses', 'team:id,name', 'project:id,client_id', 'aiResult:id,upload_id')))->resolve(),
            'clients' => $this->clients->options($request->user()),
            'projects' => app(ProjectDirectory::class)->options($request->user()),
            // The same list the create form offers, so a job can be corrected
            // onto the right drawing instead of being raised again.
            'uploads' => $this->linkOptions->uploads(),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            /*
             * Carried, not resolved: this screen's own Back goes to the job, and
             * it is the job that needs to know where the trail started. Without
             * this, editing a job reached from Scheduling would drop someone at
             * the jobs list on the way out.
             */
            'from' => $this->originName($request),
        ]);
    }

    /** The origin as it came in, once it is one this app serves. */
    /**
     * The drawing the job is taken off, when the edit form moves it.
     *
     * A job raised by hand can be pointed at another drawing — its project may
     * have gained one since, or the wrong one was picked. A job raised from a
     * reviewed takeoff cannot: it carries that review's own counts, and
     * pointing it at a different drawing would leave the numbers on the job
     * describing a drawing it is no longer on.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRelinkedDrawing(Job $job, array $data): array
    {
        $uploadId = $data['upload_id'] ?? null;
        unset($data['upload_id']);

        if ($uploadId === null || ! empty($job->symbol_counts)) {
            return $data;
        }

        $upload = Upload::find($uploadId);

        // The drawing has to be one of the project's own — the form narrows the
        // list to them, and a hand-made request is held to the same rule.
        if (! $upload || (int) $upload->project_id !== (int) $data['project_id']) {
            return $data;
        }

        $data['ai_result_id'] = $upload->latestAiResult?->id;

        return $data;
    }

    private function originName(Request $request): ?string
    {
        return JobOrigin::name($request->query('from'));
    }

    public function update(UpdateJobRequest $request, Job $job): RedirectResponse
    {
        $this->authorize('update', $job);

        $data = $this->clients->withClientSnapshot($request->validated(), $request->user());
        $newStatus = $data['status'];
        unset($data['status']);

        // Against the client's own book — a project and its job share a place.
        $addresses = $this->sites->resolve((int) $data['client_id'], $data['address_ids']);
        unset($data['address_ids']);

        $data = $this->withRelinkedDrawing($job, $data);

        $job->update($data);
        $this->sites->attach($job, $addresses);
        $job->recordActivity('updated', 'Job details updated');

        // Recorded separately so the status trail stays authoritative.
        $job->changeStatus($newStatus);

        return redirect()
            ->route('jobs.show', array_filter([
                'job' => $job->id,
                // So Back from the job still points where the trail started.
                'from' => $this->originName($request),
            ]))
            ->with('success', "“{$job->name}” was updated.");
    }

    public function destroy(Job $job): RedirectResponse
    {
        $this->authorize('delete', $job);

        $job->recordActivity('deleted', 'Job deleted');
        $job->delete();

        return redirect()
            ->route('jobs.index')
            ->with('warning', "“{$job->name}” was deleted.")
            // Lets the list offer Undo for a delete made on the detail screen.
            ->with('restore_job_id', $job->id);
    }

    /** Undo for the delete above. */
    public function restore(Request $request, int $job): RedirectResponse
    {
        $trashed = Job::onlyTrashed()->ownedBy($request->user())->findOrFail($job);
        $trashed->restore();
        $trashed->recordActivity('restored', 'Job restored');

        return back()->with('success', "“{$trashed->name}” was restored.");
    }

    public function archive(Job $job): RedirectResponse
    {
        $this->authorize('update', $job);

        $job->update(['archived_at' => now()]);
        $job->recordActivity('archived', 'Job archived');

        return back()->with('warning', "“{$job->name}” was archived.");
    }

    public function unarchive(Job $job): RedirectResponse
    {
        $this->authorize('update', $job);

        $job->update(['archived_at' => null]);
        $job->recordActivity('unarchived', 'Job restored from the archive');

        return back()->with('success', "“{$job->name}” was moved out of the archive.");
    }

    /** Copies the record, its team and its intake options into a new draft. */
    public function duplicate(Job $job): RedirectResponse
    {
        $this->authorize('view', $job);

        $copy = Job::create([
            'name' => "{$job->name} (Copy)",
            'project_id' => $job->project_id,
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
        $this->authorize('update', $job);

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
            'ids.*' => ['integer', Rule::exists('work_jobs', 'id')->where('user_id', $request->user()->id)],
            'action' => ['required', Rule::in(['archive', 'unarchive', 'delete', 'status'])],
            'status' => ['nullable', Rule::in(Job::STATUSES), 'required_if:action,status'],
        ]);

        // Belt and braces: even a ownership-scoped `exists` rule only checked
        // the ids at validation time — this is what actually keeps the bulk
        // action from touching a job that is not this manager's own.
        $jobs = Job::whereIn('id', $validated['ids'])->ownedBy($request->user())->get();
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

    /** Creates a linked estimate carrying the job's client and budget. */
    private function makeEstimateFor(Job $job, User $user): Estimate
    {
        return Estimate::create([
            'job_id' => $job->id,
            'project_id' => $job->project_id,
            'number' => Estimate::nextNumber($user),
            // Both name columns are the client's — see ClientDirectory.
            'client' => $job->client ?? 'Unassigned',
            'project' => $job->client ?? 'Unassigned',
            'issued_on' => now()->toDateString(),
            'amount' => $job->budget ?? 0,
            // Raised against a job that already exists — see Estimate::statusFor().
            'status' => Estimate::statusFor($job),
        ]);
    }
}
