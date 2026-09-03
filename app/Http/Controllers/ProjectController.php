<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectDocumentResource;
use App\Http\Resources\ProjectListResource;
use App\Models\Client;
use App\Models\FeedItem;
use App\Models\Project;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Clients\ClientDirectory;
use App\Services\Takeoff\TakeoffFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients — the record a takeoff, an estimate and a job all hang off. Clients
 * and projects are the same thing, so this controller backs both names.
 *
 * The AI Takeoff module creates one implicitly, named after the drawing it was
 * given. This module creates one deliberately: the client, the site and the
 * type are recorded up front. Its drawings are not — a PDF is uploaded through
 * AI Takeoff, against a client that already exists.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly FeedItemRecorder $activity,
        private readonly ClientDirectory $clients,
    ) {}

    /**
     * Every project, under the client it is for.
     *
     * Paged by client, not by project, because the screen is grouped by client
     * — and it lets a client with no projects yet appear, so there is somewhere
     * to add the first one from.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Project::STATUSES])],
        ]);

        $status = $filters['status'] ?? 'all';
        $search = trim($filters['search'] ?? '');
        $narrowed = $search !== '' || $status !== 'all';

        $matching = fn ($query) => $query
            ->when($search !== '', fn ($inner) => $inner->where('name', 'like', "%{$search}%"))
            ->when($status !== 'all', fn ($inner) => $inner->where('status', $status));

        $clients = $request->user()->clients()
            // Narrowed, a client earns its place by having a project that
            // matches; unnarrowed, every client is listed so any of them can be
            // added to.
            ->when($narrowed, fn ($query) => $query->whereHas('projects', $matching))
            ->with([
                // Newest or most recently changed first, within each client.
                'projects' => fn ($query) => $matching($query)
                    ->withCount(['uploads', 'aiResults'])
                    ->reorder()
                    ->latest('updated_at')
                    ->latest('id'),
            ])
            // And the clients themselves in the order their work last moved.
            ->reorder()
            ->latest('updated_at')
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'projects' => $client->projects->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'code' => $project->code,
                    'status' => $project->status,
                    'projectType' => $project->project_type,
                    'drawingCount' => $project->uploads_count,
                    'takeoffCount' => $project->ai_results_count,
                    'createdAt' => $project->created_at?->toISOString(),
                ])->values(),
            ]);

        return Inertia::render('Projects', [
            // Wrapped, not handed over raw: a bare paginator serialises flat
            // and the screen reads `meta.current_page` to draw its pager.
            'clients' => JsonResource::collection($clients),
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => Project::STATUSES,
        ]);
    }

    /**
     * Full-page create form: the project's own details, under a client.
     *
     * No drawings arrive with it — a PDF is uploaded through AI Takeoff against
     * a project that already exists, so the product has one upload path.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('ProjectCreate', [
            'clients' => $this->clients->options(),
            /*
             * Opened from a client's own screen, that client is the answer and
             * the form should not ask again. Checked against the owner rather
             * than trusted.
             */
            'defaultClientId' => $request->user()->clients()
                ->whereKey($request->integer('client'))
                ->value('id'),
            /*
             * A takeoff already on the go is worth saying out loud: opening a
             * second project is a normal thing to do, but doing it by accident
             * and losing track of the first is not.
             */
            'unfinishedTakeoff' => app(TakeoffFlow::class)->inProgress($request),
        ]);
    }

    /**
     * Records the project against its client.
     *
     * Nothing is sent to the AI engine here — this opens the project, and a
     * takeoff is started separately from the AI Takeoff module.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $client = $request->user()->clients()->with('primaryAddress')->findOrFail($data['client_id']);

        /*
         * The project's location is the client's primary site, snapshotted. A
         * project and the job on it are at the same place, so it is recorded
         * once and read from here — never asked for twice.
         */
        $site = $client->primaryAddress;

        $project = $request->user()->projects()->create([
            'client_id' => $client->id,
            'name' => $data['name'],
            // The client's name, snapshotted: every list, filter and printed
            // document already reads this column rather than the join.
            'client' => $client->name,
            'location' => $site?->address,
            'latitude' => $site?->latitude,
            'longitude' => $site?->longitude,
            'place_id' => $site?->place_id,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        // The takeoff starts here: this project's drawing is the next step, and
        // the resume button follows it until its job has tasks.
        app(TakeoffFlow::class)->remember($project);

        $project->activities()->create([
            'title' => 'Project opened',
            'description' => 'No drawings yet — upload one from AI Takeoff',
            'tone' => 'brand',
            'occurred_at' => now(),
        ]);

        $this->activity->record(
            FeedItem::DASHBOARD_ACTIVITY,
            "New project opened: {$project->name}",
            'briefcase',
            'lilac',
        );

        /*
         * Remembered for the AI Takeoff upload screen, which preselects it —
         * someone who has just opened a project and gone to upload a drawing
         * means that project, and should not have to find it in the list again.
         * Read once and cleared, so it never quietly steers a later upload.
         */
        $request->session()->put('takeoff.preselected_client', $project->id);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "“{$project->name}” was opened.");
    }

    /** Project detail: its details, its drawing PDFs and what has happened to it. */
    public function show(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        $project->loadCount('uploads');
        $documents = $project->uploads()->oldest()->get();

        return Inertia::render('ProjectShow', [
            'project' => [
                ...ProjectListResource::make($project)->resolve($request),
                'notes' => $project->notes,
                'drawingName' => $project->drawing_name,
                // Which drawing the next takeoff runs against. Falls back to
                // the first on record when nothing has been chosen.
                'selectedUploadId' => $project->takeoffDrawing()?->id,
                'pageCount' => $project->page_count,
                'startedAt' => $project->started_at?->toISOString(),
                'completedAt' => $project->completed_at?->toISOString(),
                // Set once the engine has returned something for this project, so
                // the screen can hand off to the takeoff instead of restating it.
                'takeoffUrl' => $project->aiResults()->exists()
                    ? route('drawings.show', $project)
                    : null,
            ],
            'documents' => ProjectDocumentResource::collection($documents)->resolve($request),
        ]);
    }

    /**
     * Soft deletes the project. Its PDFs stay on the disk — a restore from the
     * takeoff history has to come back whole.
     */
    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return redirect()
            ->route('projects.index')
            ->with('warning', "“{$project->name}” was deleted.");
    }
}
