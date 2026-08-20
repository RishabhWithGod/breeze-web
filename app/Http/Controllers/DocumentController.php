<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentShare;
use App\Models\Job;
use App\Models\Upload;
use App\Models\User;
use App\Notifications\DocumentShared;
use App\Notifications\DocumentVersionUploaded;
use App\Policies\DocumentPolicy;
use App\Services\Documents\DocumentStore;
use App\Support\UploadLimits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    private const TABS = ['all', 'recent', 'shared', 'favorites', 'archived'];

    public function __construct(private readonly DocumentStore $documents) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'document_type' => ['nullable', Rule::in(['all', ...Document::TYPES])],
            'job_id' => ['nullable', 'integer'],
            'estimate_id' => ['nullable', 'integer'],
            'modified' => ['nullable', Rule::in(['all', 'today', '7', '30', '90'])],
            'version_status' => ['nullable', Rule::in(['all', 'latest', 'superseded'])],
            'tab' => ['nullable', Rule::in(self::TABS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $policy = app(DocumentPolicy::class);
        $canManageAll = $policy->abilities($user)['manage'];

        $search = $filters['search'] ?? '';
        $documentType = $filters['document_type'] ?? 'all';
        $jobId = $filters['job_id'] ?? null;
        $estimateId = $filters['estimate_id'] ?? null;
        $modified = $filters['modified'] ?? 'all';
        // Defaults to latest-only: a version family is one row until the user
        // explicitly asks to see superseded versions too (or opens History).
        $versionStatus = $filters['version_status'] ?? 'latest';
        $tab = $filters['tab'] ?? 'all';

        $base = fn () => Document::query()
            ->visibleTo($user, $canManageAll)
            ->search($search)
            ->ofType($documentType)
            ->forJob($jobId)
            ->when($estimateId, fn (Builder $q) => $q->where('estimate_id', $estimateId))
            ->modifiedSince($modified === 'all' ? null : $modified)
            ->versionStatus($versionStatus === 'all' ? null : $versionStatus);

        $documents = $this->scopeToTab($base(), $tab, $user)
            ->with(['job', 'estimate', 'folder', 'uploader', 'upload'])
            ->orderByDesc('updated_at')
            ->paginate(config('documents.per_page'))
            ->withQueryString();

        $counts = [];
        foreach (self::TABS as $tabOption) {
            $counts[$tabOption] = $this->scopeToTab($base(), $tabOption, $user)->count();
        }

        return Inertia::render('Documents', [
            'documents' => DocumentResource::collection($documents),
            'filters' => [
                'search' => $search,
                'document_type' => $documentType,
                'job_id' => $jobId,
                'modified' => $modified,
                'version_status' => $versionStatus,
                'tab' => $tab,
            ],
            'documentTypes' => Document::TYPES,
            'tabCounts' => $counts,
            'can' => $policy->abilities($user),
            'shareableUsers' => User::query()->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']),
            // The list page only needs these for its filter dropdowns and the
            // "upload a new version" / "new folder" actions — the AI Takeoff
            // import picker belongs to the dedicated Upload Document screen.
            'jobs' => Job::query()->orderBy('name')->get(['id', 'name'])->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
            ]),
            'folders' => DocumentFolder::query()->orderBy('name')->get(['id', 'name', 'parent_id', 'job_id']),
            'maxFileSizeMb' => UploadLimits::effectiveMb(),
        ]);
    }

    /** The Upload Document screen — a real page, not a popup, so it survives a refresh and gets its own URL. */
    public function create(Request $request): Response
    {
        $this->authorize('create', Document::class);

        return Inertia::render('DocumentUpload', [
            'jobId' => $request->integer('job_id') ?: null,
            ...$this->uploadFormProps(),
        ]);
    }

    /** Shared between the list page's filter panel and the Upload Document screen. */
    private function uploadFormProps(): array
    {
        return [
            'jobs' => Job::query()->orderBy('name')->get(['id', 'name'])->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
            ]),
            'folders' => DocumentFolder::query()->orderBy('name')->get(['id', 'name', 'parent_id', 'job_id']),
            'maxFileSizeMb' => UploadLimits::effectiveMb(),
            // Drawings AI Takeoff already has on file — importable without a second upload of the same bytes.
            'importableUploads' => Upload::query()
                ->whereNotNull('path')
                ->whereDoesntHave('documents')
                ->with('project')
                ->latest()
                ->take(100)
                ->get()
                ->map(fn (Upload $upload) => [
                    'id' => $upload->id,
                    'label' => $upload->label(),
                    'projectId' => $upload->project_id,
                    'projectName' => $upload->project?->name,
                ]),
        ];
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $file = $request->file('file');
        $name = filled($data['name'] ?? null) ? $data['name'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = $this->documents->create($file, [
            'name' => $name,
            'document_type' => $data['document_type'],
            'job_id' => $data['job_id'] ?? null,
            'estimate_id' => $data['estimate_id'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'],
        ], $request->user());

        $document->recordActivity('uploaded', "“{$document->name}” was uploaded");

        return redirect()->route('documents.index')->with('success', "“{$document->name}” was uploaded.");
    }

    /**
     * Registers an existing AI Takeoff drawing as a document — no second copy
     * of the bytes. `storage_path` is the same file `uploads.path` already
     * points at, so preview/download/versioning all work against it directly.
     */
    public function importFromUpload(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'upload_id' => ['required', 'integer', 'exists:uploads,id'],
            'name' => ['nullable', 'string', 'max:150'],
            'document_type' => ['required', Rule::in(Document::TYPES)],
            'job_id' => ['nullable', 'integer', 'exists:work_jobs,id'],
            'estimate_id' => ['nullable', 'integer', 'exists:estimates,id'],
            'folder_id' => ['nullable', 'integer', 'exists:document_folders,id'],
            'visibility' => ['required', Rule::in([Document::VISIBILITY_TEAM, Document::VISIBILITY_PRIVATE])],
        ]);

        $upload = Upload::findOrFail($data['upload_id']);
        abort_if(blank($upload->path), 404, 'That drawing has no file on disk to import.');

        $document = Document::create([
            'name' => filled($data['name'] ?? null) ? $data['name'] : $upload->label(),
            'original_filename' => $upload->name,
            'storage_path' => $upload->path,
            'mime_type' => 'application/pdf',
            'extension' => strtolower(pathinfo($upload->name, PATHINFO_EXTENSION)) ?: strtolower($upload->format),
            'file_size' => $upload->size_bytes,
            'document_type' => $data['document_type'],
            'job_id' => $data['job_id'] ?? null,
            'estimate_id' => $data['estimate_id'] ?? null,
            'folder_id' => $data['folder_id'] ?? null,
            'visibility' => $data['visibility'],
            'uploaded_by' => $request->user()->id,
            'upload_id' => $upload->id,
            'version' => 1,
            'version_root_id' => null,
            'is_latest' => true,
        ]);

        $document->recordActivity('imported', "“{$document->name}” was imported from AI Takeoff");

        return redirect()->route('documents.index')->with('success', "“{$document->name}” was added from AI Takeoff.");
    }

    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('manageVersions', $document);

        $version = $this->documents->createNewVersion($document, $request->file('file'), $request->user(), $request->input('description'));

        $version->recordActivity('version_created', "Version {$version->version} uploaded for “{$version->name}”");

        $interested = $version->favoritedBy()->pluck('users.id')
            ->push($document->uploaded_by)
            ->unique()
            ->reject(fn ($id) => $id === $request->user()->id);

        foreach (User::whereIn('id', $interested)->get() as $recipient) {
            $recipient->notify(new DocumentVersionUploaded($version, $request->user()));
        }

        return back()->with('success', "“{$version->name}” v{$version->version} is now the latest version.");
    }

    /** The file itself, streamed inline so the browser's own viewer renders it in place. */
    public function preview(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk(config('documents.disk'));
        abort_unless($disk->exists($document->storage_path), 404);

        $document->recordActivity('viewed', "“{$document->name}” was viewed");

        return $disk->response($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($document->original_filename).'"',
        ]);
    }

    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk(config('documents.disk'));
        abort_unless($disk->exists($document->storage_path), 404);

        $document->recordActivity('downloaded', "“{$document->name}” was downloaded");

        return $disk->download($document->storage_path, $document->original_filename);
    }

    /** @return AnonymousResourceCollection<DocumentResource> */
    public function history(Document $document): AnonymousResourceCollection
    {
        $this->authorize('view', $document);

        $versions = $document->versionFamily()->with(['uploader'])->get();

        return DocumentResource::collection($versions);
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $name = $document->name;
        $version = $document->version;
        $this->documents->delete($document);

        $document->recordActivity('deleted', "Version {$version} of “{$name}” was deleted");

        return back()->with('warning', "“{$name}” v{$version}.0 was deleted.");
    }

    public function archive(Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $document->update(['is_archived' => true, 'archived_at' => now()]);
        $document->recordActivity('archived', "“{$document->name}” was archived");

        return back()->with('warning', "“{$document->name}” was archived.");
    }

    public function restore(Document $document): RedirectResponse
    {
        $this->authorize('archive', $document);

        $document->update(['is_archived' => false, 'archived_at' => null]);
        $document->recordActivity('restored', "“{$document->name}” was restored");

        return back()->with('success', "“{$document->name}” was restored.");
    }

    public function favorite(Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $user = Auth::user();

        if ($document->isFavoritedBy($user)) {
            $document->favoritedBy()->detach($user->id);
        } else {
            $document->favoritedBy()->attach($user->id);
        }

        return back();
    }

    public function share(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('share', $document);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$request->user()->id])],
            'permission' => ['required', Rule::in(['view', 'edit'])],
        ]);

        DocumentShare::updateOrCreate(
            ['document_id' => $document->id, 'shared_with_user_id' => $data['user_id']],
            ['shared_by_user_id' => $request->user()->id, 'permission' => $data['permission']],
        );

        $document->recordActivity('shared', "“{$document->name}” was shared");

        User::find($data['user_id'])?->notify(new DocumentShared($document, $request->user()));

        return back()->with('success', "“{$document->name}” was shared.");
    }

    private function scopeToTab(Builder $query, string $tab, $user): Builder
    {
        return match ($tab) {
            'recent' => $query->where('is_archived', false)->where('updated_at', '>=', now()->subDays(30)),
            'shared' => $query->where('is_archived', false)->whereHas('shares', fn (Builder $q) => $q->where('shared_with_user_id', $user->id)),
            'favorites' => $query->where('is_archived', false)->whereHas('favoritedBy', fn (Builder $q) => $q->where('users.id', $user->id)),
            'archived' => $query->where('is_archived', true),
            default => $query->where('is_archived', false),
        };
    }
}
