<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Job;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Drawings and documents a crew needs to do the work — everything filed under
 * the job itself, plus the project it belongs to. Read and download only:
 * filing, versioning and sharing stay on the web. A document marked private
 * is never offered to anyone but whoever uploaded it.
 */
class JobDocumentController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ElectricianJobAccess $access) {}

    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $documents = $job->documentsFor($request->user())
            ->orderByDesc('created_at')
            ->get();

        return $this->ok([
            'documents' => $documents->map(fn (Document $d) => $this->present($d))->all(),
        ]);
    }

    public function download(Request $request, Job $job, Document $document): StreamedResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_unless($job->documentsFor($request->user())->whereKey($document->id)->exists(), 404);

        $disk = Storage::disk(config('documents.disk'));
        abort_unless($disk->exists($document->storage_path), 404);

        return $disk->download($document->storage_path, $document->original_filename);
    }

    /** @return array<string, mixed> */
    private function present(Document $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'filename' => $document->original_filename,
            'type' => $document->document_type,
            'extension' => $document->extension,
            'mimeType' => $document->mime_type,
            'size' => $document->file_size,
            'version' => $document->version,
            'uploadedAt' => $document->created_at?->toISOString(),
        ];
    }
}
