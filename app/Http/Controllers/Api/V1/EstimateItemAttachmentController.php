<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\EstimateItem;
use App\Models\EstimateItemAttachment;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One material line's own photos — the per-material analogue of
 * `JobTaskAttachmentController`, now that the mobile Materials screen
 * attaches a photo per line instead of one for the whole task. Only images,
 * same reasoning as the task-scoped controller: this is the crew's "photo of
 * this specific material/fixture", not a general document upload.
 */
class EstimateItemAttachmentController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function index(Request $request, EstimateItem $item): JsonResponse
    {
        abort_unless($item->job_task_id !== null, 404, 'This line is not part of a task yet.');
        $task = $item->task;
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        $photos = $item->attachments()->with('uploader:id,name')->latest('id')->get();

        return $this->ok([
            'photos' => $photos->map(fn (EstimateItemAttachment $a) => $this->present($request, $a))->values(),
        ]);
    }

    public function store(Request $request, EstimateItem $item): JsonResponse
    {
        abort_unless($item->job_task_id !== null, 404, 'This line is not part of a task yet.');
        $task = $item->task;
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        if ($task->job?->isReadyForReview() && ! $this->policy->reopenTask($request->user(), $task)) {
            return $this->fail(
                'This job has been submitted for review — wait for your supervisor to act on it.',
                409,
            );
        }

        $data = $request->validate([
            'file' => ['required', 'image', 'max:20480'],
        ], [
            'file.image' => 'Only photos can be attached here.',
            'file.max' => 'Photos must be 20 MB or smaller.',
        ]);

        $file = $data['file'];
        $path = $file->store("estimate-item-attachments/{$item->id}", 'local');

        $attachment = $item->attachments()->create([
            'uploaded_by' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
        $attachment->load('uploader:id,name');

        return $this->created($this->present($request, $attachment), 'Photo uploaded.');
    }

    /** Sanctum-authenticated bytes, mirroring `JobTaskAttachmentController::show()`. */
    public function show(Request $request, EstimateItemAttachment $attachment): StreamedResponse
    {
        abort_unless($this->access->canAccess($request->user(), $attachment->item->task->job), 403);

        return response()->streamDownload(
            fn () => print(Storage::disk('local')->get($attachment->path)),
            $attachment->name,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream'],
            'inline',
        );
    }

    /** @return array<string, mixed> */
    private function present(Request $request, EstimateItemAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->name,
            'url' => route('api.v1.estimate-items.attachments.show', $attachment->id),
            'mimeType' => $attachment->mime_type,
            'sizeBytes' => $attachment->size_bytes,
            'uploadedBy' => $attachment->uploader?->name,
            'createdAt' => $attachment->created_at?->toISOString(),
        ];
    }
}
