<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectDocumentResource;
use App\Http\Resources\ProjectListResource;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\Project;
use App\Services\Activity\FeedItemRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function __construct(private readonly FeedItemRecorder $activity) {}

    /** Search, status filter, sort and pagination all run in the database. */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...Project::STATUSES])],
            'sort' => ['nullable', Rule::in(Project::SORTS)],
        ]);

        $status = $filters['status'] ?? 'all';
        $sort = $filters['sort'] ?? 'recent';

        $projects = Project::query()
            ->where('user_id', $request->user()->id)
            ->withCount('uploads')
            ->search($filters['search'] ?? null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->sorted($sort)
            ->paginate(config('takeoff.per_page'))
            ->withQueryString();

        return Inertia::render('Projects', [
            'projects' => ProjectListResource::collection($projects),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $status,
                'sort' => $sort,
            ],
            'counts' => [
                'total' => $request->user()->projects()->count(),
                'drafts' => $request->user()->projects()->where('status', 'draft')->count(),
                'documents' => $request->user()->uploads()->whereNotNull('project_id')->count(),
            ],
        ]);
    }

    /** Full-page create form: the client's own details, and nothing else. */
    public function create(): Response
    {
        return Inertia::render('ProjectCreate', [
            'clients' => $this->knownClients(),
        ]);
    }

    /**
     * Records the client. No drawings arrive with it: a PDF is uploaded through
     * AI Takeoff, against a client that already exists.
     *
     * Nothing is sent to the AI engine here either — this opens the client, and
     * a takeoff is started separately from the AI Takeoff module.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $addresses = array_values($data['addresses'] ?? []);
        $primary = $addresses[0] ?? null;

        $project = $request->user()->projects()->create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            // Clients are projects: the name is the client, so the column
            // is written from it rather than typed a second time.
            'client' => $data['name'],
            // The primary site, mirrored here because every list, search and
            // job screen already reads `location` off the client.
            'location' => $primary['address'] ?? null,
            'latitude' => $primary['latitude'] ?? null,
            'longitude' => $primary['longitude'] ?? null,
            'project_type' => $data['project_type'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'review_status' => 'none',
        ]);

        foreach ($addresses as $position => $address) {
            $project->addresses()->create([
                'label' => $address['label'] ?? null,
                'address' => $address['address'],
                'latitude' => $address['latitude'] ?? null,
                'longitude' => $address['longitude'] ?? null,
                // The first one given is the one a job defaults to.
                'is_primary' => $position === 0,
                'position' => $position,
            ]);
        }

        $project->activities()->create([
            'title' => 'Client created',
            'description' => 'No drawings yet — upload one from AI Takeoff',
            'tone' => 'brand',
            'occurred_at' => now(),
        ]);

        $this->activity->record(FeedItem::DASHBOARD_ACTIVITY, "New client created: {$project->name}", 'briefcase', 'lilac');

        /*
         * Remembered for the AI Takeoff upload screen, which preselects it —
         * someone who has just created a client and gone to upload a drawing
         * means that client, and should not have to find it in the list again.
         * Read once and cleared, so it never quietly steers a later upload.
         */
        $request->session()->put('takeoff.preselected_client', $project->id);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', "“{$project->name}” was created.");
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

    /** Clients already on record, offered in the Client select. */
    private function knownClients(): Collection
    {
        return Project::query()
            ->whereNotNull('client')
            ->pluck('client')
            ->merge(Job::query()->whereNotNull('client')->pluck('client'))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
