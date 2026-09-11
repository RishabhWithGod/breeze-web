<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\FeedItem;
use App\Models\Upload;
use App\Models\User;
use App\Notifications\DocumentShared;
use App\Notifications\DocumentVersionUploaded;
use App\Policies\DocumentPolicy;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Documents\DocumentStore;
use App\Support\UploadLimits;
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
    public function __construct(
        private readonly DocumentStore $documents,
        private readonly FeedItemRecorder $activity,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);

        $user = $request->user();

        $filters = $request->validate([
            /*
             * The takeoff whose paperwork this is. Documents are filed against
             * a project, so the list is that project's rather than everyone's.
             * Not a filter: it is which list this is.
             *
             * It has to be one of this manager's own projects. Without that,
             * anyone could name another manager's project id in the query
             * string and read their filing cabinet.
             */
            'project' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('user_id', $user->id)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $policy = app(DocumentPolicy::class);
        $canManageAll = $policy->abilities($user)['manage'];

        $projectId = $filters['project'] ?? null;

        /*
         * Latest versions only. That was a filter anyone could turn off; it is
         * now simply the rule, because a version family reads as one document
         * and its earlier versions are opened through History.
         */
        /*
         * Confined to this manager's own projects before anything else is
         * asked. `visibleTo()` decides who may see a *private* document, and a
         * "manage" role turns that check off entirely — neither has anything
         * to say about whose project the document sits on, so on its own it
         * would show an admin every manager's paperwork. This matches
         * `DocumentPolicy::view()`, which now refuses a document that is not
         * on a project of the user's: the list must never offer what opening
         * it would 403 on.
         */
        $base = fn () => Document::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->visibleTo($user, $canManageAll)
            ->forProject($projectId)
            ->versionStatus('latest');

        /*
         * Everything, newest first — an upload lands at the top. Archived
         * documents are in the list too, marked rather than filed behind a tab
         * that no longer exists: hiding them would leave nothing to restore
         * them from.
         */
        $documents = $base()
            ->with(['job', 'estimate', 'uploader', 'upload'])
            ->orderByDesc('updated_at')
            ->paginate(config('documents.per_page'))
            ->withQueryString();

        return Inertia::render('Documents', [
            'documents' => DocumentResource::collection($documents),
            'filters' => ['project' => $projectId],
            /*
             * The takeoff being read, when the list was opened from one. It is
             * what the screen names itself after and what Back returns to —
             * without it this is the whole workspace's paperwork.
             */
            'takeoff' => $projectId === null ? null : (function () use ($projectId, $request) {
                $project = $request->user()->projects()->with('clientRecord:id,name')->find($projectId);

                return $project === null ? null : [
                    'id' => $project->id,
                    'name' => $project->name,
                    'clientName' => $project->clientRecord?->name ?? $project->client,
                    'url' => route('drawings.show', $project),
                ];
            })(),
            'documentTypes' => Document::TYPES,
            'can' => $policy->abilities($user),
            'shareableUsers' => User::query()->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']),
            // For the "upload a new version" action, which is all that is left
            // needing it.
            'maxFileSizeMb' => UploadLimits::effectiveMb(),
        ]);
    }

    /** The Upload Document screen — a real page, not a popup, so it survives a refresh and gets its own URL. */
    public function create(Request $request): Response
    {
        $this->authorize('create', Document::class);

        return Inertia::render('DocumentUpload', [
            'jobId' => $request->integer('job_id') ?: null,
            // Carried through so the document is filed under the takeoff whose
            // screen asked for it, and lands back in that list.
            'projectId' => $request->user()->projects()
                ->whereKey($request->integer('project'))
                ->value('id'),
            ...$this->uploadFormProps(),
        ]);
    }

    /** All the Upload Document screen needs: a file, and how big it may be. */
    private function uploadFormProps(): array
    {
        return ['maxFileSizeMb' => UploadLimits::effectiveMb()];
    }

    public function store(StoreDocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validated();

        $file = $request->file('file');
        $name = filled($data['name'] ?? null) ? $data['name'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = $this->documents->create($file, [
            'name' => $name,
            // Defaulted rather than asked for: the form no longer offers a
            // type, and "Other" is the honest answer when nobody said.
            'document_type' => $data['document_type'] ?? 'Other',
            'job_id' => $data['job_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'estimate_id' => $data['estimate_id'] ?? null,
            'description' => $data['description'] ?? null,
            // Team by default. A document nobody can see is not a filing
            // system, and the form no longer asks.
            'visibility' => $data['visibility'] ?? Document::VISIBILITY_TEAM,
        ], $request->user());

        $document->recordActivity('uploaded', "“{$document->name}” was uploaded");

        $this->activity->record($request->user(), FeedItem::DASHBOARD_ACTIVITY, "Document uploaded: {$document->name}", 'file-text', 'lilac');

        // Back to the list it was uploaded from — a takeoff's own, when it
        // was filed under one.
        return redirect()
            ->route('documents.index', array_filter(['project' => $document->project_id]))
            ->with('success', "“{$document->name}” was uploaded.");
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
}
