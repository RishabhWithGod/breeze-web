<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DocumentShared;
use App\Notifications\DocumentVersionUploaded;
use App\Services\Documents\DocumentStore;
use App\Support\Ownership;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A project's own paperwork — mobile's counterpart to web's own
 * `DocumentController::index()`/`store()`/`destroy()`, scoped to one AI
 * Takeoff project rather than the whole workspace (mobile has no
 * cross-project Documents module — see the "Documents" entry on Drawing
 * Details). List/upload/delete only: versioning, sharing, favoriting and
 * archiving stay web-only, back-office actions. Reuses `DocumentStore`
 * exactly as web does — no second implementation of how a file is written
 * or removed.
 */
class DocumentController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly DocumentStore $documents) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $documents = Document::query()
            ->where('project_id', $project->id)
            ->where('is_latest', true)
            // Archived ones stay in the list, marked — hiding them would leave
            // nothing to restore them from (web does the same).
            ->orderByDesc('updated_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'documents' => $documents->getCollection()
                ->map(fn (Document $document) => $this->summarize($document, $request))
                ->all(),
            // Who a document can be shared with.
            'shareableUsers' => User::query()->where('id', '!=', $request->user()->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'maxFileSizeMb' => UploadLimits::effectiveMb(),
            'extensions' => config('documents.extensions'),
            'meta' => [
                'currentPage' => $documents->currentPage(),
                'lastPage' => $documents->lastPage(),
                'perPage' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $project->user_id), 403);

        $data = $request->validate([
            'file' => [
                'required',
                'file',
                // The effective limit, not the configured one: PHP's own
                // ceiling sits underneath and would drop the file first.
                'max:'.(UploadLimits::effectiveMb() * 1024),
                Rule::file()->extensions(config('documents.extensions')),
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'The file must be under '.UploadLimits::effectiveMb().' MB.',
            'file.extensions' => 'Unsupported file type — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", config('documents.extensions'))),
        ]);

        $file = $request->file('file');
        $name = filled($data['name'] ?? null)
            ? $data['name']
            : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $document = $this->documents->create($file, [
            'name' => $name,
            'document_type' => 'Other',
            'project_id' => $project->id,
            'description' => $data['description'] ?? null,
            'visibility' => Document::VISIBILITY_TEAM,
        ], $request->user());

        $document->recordActivity('uploaded', "\"{$document->name}\" was uploaded");

        return $this->created($this->summarize($document, $request), "\"{$document->name}\" was uploaded.");
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $document->project?->user_id), 403);

        $name = $document->name;
        $this->documents->delete($document);

        return $this->ok(null, "\"{$name}\" was deleted.");
    }

    /** `GET /documents/{document}/file` — the file itself, inline (`?download=1` to save it). */
    public function file(Request $request, Document $document): StreamedResponse
    {
        $this->authoriseView($request, $document);

        $disk = Storage::disk(config('documents.disk'));
        abort_unless($disk->exists($document->storage_path), 404);

        $document->recordActivity($request->boolean('download') ? 'downloaded' : 'viewed', "\"{$document->name}\" was ".($request->boolean('download') ? 'downloaded' : 'viewed'));

        return $disk->response($document->storage_path, $document->original_filename, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.addslashes($document->original_filename).'"',
        ]);
    }

    /** `GET /documents/{document}/history` — every version of it, newest first. */
    public function history(Request $request, Document $document): JsonResponse
    {
        $this->authoriseView($request, $document);

        return $this->ok([
            'versions' => $document->versionFamily()->with('uploader')->get()
                ->map(fn (Document $version) => $this->summarize($version, $request))->all(),
        ]);
    }

    /** `POST /documents/{document}/versions` — a new version becomes the latest. */
    public function storeVersion(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('manageVersions', $document), 403);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.(UploadLimits::effectiveMb() * 1024), Rule::file()->extensions(config('documents.extensions'))],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'The file must be under '.UploadLimits::effectiveMb().' MB.',
        ]);

        $version = $this->documents->createNewVersion($document, $request->file('file'), $request->user(), $data['description'] ?? null);

        $version->recordActivity('version_created', "Version {$version->version} uploaded for \"{$version->name}\"");

        $interested = $version->favoritedBy()->pluck('users.id')
            ->push($document->uploaded_by)
            ->unique()
            ->reject(fn ($id) => $id === $request->user()->id);

        foreach (User::whereIn('id', $interested)->get() as $recipient) {
            $recipient->notify(new DocumentVersionUploaded($version, $request->user()));
        }

        return $this->created($this->summarize($version, $request), "\"{$version->name}\" v{$version->version} is now the latest version.");
    }

    /** `POST /documents/{document}/favorite` — toggles the signed-in user's star. */
    public function favorite(Request $request, Document $document): JsonResponse
    {
        $this->authoriseView($request, $document);

        $user = $request->user();

        if ($document->isFavoritedBy($user)) {
            $document->favoritedBy()->detach($user->id);
        } else {
            $document->favoritedBy()->attach($user->id);
        }

        return $this->ok(['isFavorite' => $document->isFavoritedBy($user)]);
    }

    public function archive(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('archive', $document), 403);

        $document->update(['is_archived' => true, 'archived_at' => now()]);
        $document->recordActivity('archived', "\"{$document->name}\" was archived");

        return $this->ok(null, "\"{$document->name}\" was archived.");
    }

    public function restore(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('archive', $document), 403);

        $document->update(['is_archived' => false, 'archived_at' => null]);
        $document->recordActivity('restored', "\"{$document->name}\" was restored");

        return $this->ok(null, "\"{$document->name}\" was restored.");
    }

    /** `POST /documents/{document}/share` — view or edit access for one person. */
    public function share(Request $request, Document $document): JsonResponse
    {
        abort_unless($request->user()->can('share', $document), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$request->user()->id])],
            'permission' => ['required', Rule::in(['view', 'edit'])],
        ]);

        DocumentShare::updateOrCreate(
            ['document_id' => $document->id, 'shared_with_user_id' => $data['user_id']],
            ['shared_by_user_id' => $request->user()->id, 'permission' => $data['permission']],
        );

        $document->recordActivity('shared', "\"{$document->name}\" was shared");

        User::find($data['user_id'])?->notify(new DocumentShared($document, $request->user()));

        return $this->ok(null, "\"{$document->name}\" was shared.");
    }

    private function authoriseView(Request $request, Document $document): void
    {
        abort_unless(
            Ownership::owns($request->user(), $document->project?->user_id) && $request->user()->can('view', $document),
            403,
        );
    }

    /** @return array<string, mixed> */
    private function summarize(Document $document, Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $document->id,
            'name' => $document->name,
            'originalFilename' => $document->original_filename,
            'extension' => $document->extension,
            'mimeType' => $document->mime_type,
            'fileSize' => (int) $document->file_size,
            'description' => $document->description,
            'uploadedAt' => $document->created_at?->toISOString(),
            'modifiedAt' => $document->updated_at?->toISOString(),
            'version' => $document->version,
            'versionLabel' => $document->versionLabel(),
            'isLatest' => (bool) $document->is_latest,
            'isArchived' => (bool) $document->is_archived,
            'isFavorite' => $document->isFavoritedBy($user),
            'isShared' => $document->isSharedWith($user),
            'uploaderName' => $document->uploader?->name,
            'aiTakeoffProjectId' => $document->upload_id ? $document->upload?->project_id : null,
            'canEdit' => $user->can('update', $document),
            'canDelete' => $user->can('delete', $document),
            'canShare' => $user->can('share', $document),
        ];
    }
}
