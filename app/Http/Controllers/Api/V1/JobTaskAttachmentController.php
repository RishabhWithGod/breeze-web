<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\JobTask;
use App\Models\JobTaskAttachment;
use App\Policies\JobSchedulePolicy;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A task's own photos — the same `job_task_attachments` table the schema
 * already had (`JobTask::attachments()`) but nothing wrote to before this:
 * there was no store route anywhere, web or mobile. Task-scoped, same as
 * `JobTaskNoteController` — a photo of a task's work belongs with that
 * task, which is what the Materials screen groups everything by.
 *
 * Only images: this is specifically the crew's "photo of the work/material"
 * feature, not a general document upload (that already exists, job-level,
 * as `JobAttachmentController`, web-only).
 */
class JobTaskAttachmentController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function index(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');

        $photos = $task->attachments()->with('uploader:id,name')->latest('id')->get();

        return $this->ok([
            'photos' => $photos->map(fn (JobTaskAttachment $a) => $this->present($request, $a))->values(),
        ]);
    }

    public function store(Request $request, JobTask $task): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $task->job), 403, 'You are not staffed on this job.');
        abort_unless($this->policy->completeTask($request->user(), $task), 403, 'You are not assigned to this task.');
        abort_if($task->job?->isLocked(), 409, 'This job is completed and locked.');
        if ($task->job?->isReadyForReview() && ! $this->policy->reopenTask($request->user(), $task)) {
            return $this->fail(
                'This job has been submitted for review — wait for your foreman to act on it.',
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
        $path = $file->store("job-task-attachments/{$task->id}", 'local');

        $attachment = $task->attachments()->create([
            'uploaded_by' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
        $attachment->load('uploader:id,name');

        return $this->created($this->present($request, $attachment), 'Photo uploaded.');
    }

    /**
     * The bytes themselves — Sanctum-authenticated (a `Bearer` header, not
     * the web app's session cookie), so this is a real mobile-reachable
     * route rather than `JobAttachmentController::download()`'s web-only one.
     */
    public function show(Request $request, JobTaskAttachment $attachment): StreamedResponse
    {
        abort_unless($this->access->canAccess($request->user(), $attachment->task->job), 403);

        return response()->streamDownload(
            fn () => print(Storage::disk('local')->get($attachment->path)),
            $attachment->name,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream'],
            'inline',
        );
    }

    /** @return array<string, mixed> */
    private function present(Request $request, JobTaskAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->name,
            'url' => route('api.v1.tasks.attachments.show', $attachment->id),
            'mimeType' => $attachment->mime_type,
            'sizeBytes' => $attachment->size_bytes,
            'uploadedBy' => $attachment->uploader?->name,
            'createdAt' => $attachment->created_at?->toISOString(),
        ];
    }
}
