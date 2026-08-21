<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\ProjectActivityResource;
use App\Http\Resources\ProjectDocumentResource;
use App\Http\Resources\ProjectListResource;
use App\Models\FeedItem;
use App\Models\Job;
use App\Models\Project;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Takeoff\ProjectDocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects — the record a takeoff, an estimate and a job all hang off.
 *
 * The AI Takeoff module creates a project implicitly, named after the drawing it
 * was given. This module creates one deliberately: the client, the site, the type
 * and the drawing PDFs are defined up front, before anything is analysed.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectDocumentStore $documents,
        private readonly FeedItemRecorder $activity,
    ) {}

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

    /** Full-page create form: the project's details and its drawing PDFs. */
    public function create(): Response
    {
        return Inertia::render('ProjectCreate', [
            'clients' => $this->knownClients(),
            'disciplines' => Project::DISCIPLINES,
            'limits' => StoreProjectRequest::documentLimits(),
        ]);
    }

    /**
     * Records the project and the PDFs defined with it.
     *
     * Nothing is sent to the AI engine here — a project is opened to be worked on,
     * and a takeoff is started separately from the AI Takeoff module.
     */
    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $files = $request->file('documents') ?? [];
        $titles = (array) $request->input('document_titles', []);

        $project = DB::transaction(function () use ($request, $data, $files, $titles) {
            $project = $request->user()->projects()->create([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'client' => $data['client'],
                'location' => $data['location'] ?? null,
                'discipline' => $data['discipline'] ?? 'Electrical',
                'project_type' => $data['project_type'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'review_status' => 'none',
            ]);

            $uploads = $this->documents->add($project, $files, $titles, $request->user());

            // The drawing a takeoff would run against, mirrored onto the project so
            // lists can name it without loading its uploads.
            if ($uploads !== []) {
                $project->update(['drawing_name' => $uploads[0]->name]);
            }

            $project->activities()->create([
                'title' => 'Project created',
                'description' => $uploads === []
                    ? 'No drawings defined yet'
                    : count($uploads).' drawing '.(count($uploads) === 1 ? 'PDF' : 'PDFs').' defined',
                'tone' => 'brand',
                'occurred_at' => now(),
            ]);

            return $project;
        });

        $this->activity->record(FeedItem::DASHBOARD_ACTIVITY, "New project created: {$project->name}", 'briefcase', 'lilac');

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
            'activity' => ProjectActivityResource::collection($project->activities)->resolve($request),
            'limits' => StoreProjectRequest::documentLimits(),
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
