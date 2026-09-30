<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Services\Documents\DocumentStore;
use App\Support\Ownership;
use App\Support\UploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            ->where('is_archived', false)
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return $this->ok([
            'documents' => $documents->getCollection()
                ->map(fn (Document $document) => $this->summarize($document))
                ->all(),
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

        return $this->created($this->summarize($document), "\"{$document->name}\" was uploaded.");
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        abort_unless(Ownership::owns($request->user(), $document->project?->user_id), 403);

        $name = $document->name;
        $this->documents->delete($document);

        return $this->ok(null, "\"{$name}\" was deleted.");
    }

    /** @return array<string, mixed> */
    private function summarize(Document $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'originalFilename' => $document->original_filename,
            'extension' => $document->extension,
            'mimeType' => $document->mime_type,
            'fileSize' => (int) $document->file_size,
            'description' => $document->description,
            'uploadedAt' => $document->created_at?->toISOString(),
        ];
    }
}
