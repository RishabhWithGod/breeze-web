<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobAttachment;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence photos for a job, filed as the job's own attachments — so they
 * appear on the web job page under Documents the moment they are uploaded.
 */
class JobPhotoController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ElectricianJobAccess $access) {}

    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $photos = $job->attachments()->with('uploader:id,name')
            ->where('mime', 'like', 'image/%')
            ->orderBy('id')
            ->get();

        return $this->ok(['photos' => $photos->map(fn (JobAttachment $a) => $this->present($request, $job, $a))->values()]);
    }

    public function store(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_if($job->isLocked(), 409, 'This job is completed and locked.');

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:20480'],
        ], [
            'photo.image' => 'Only photos can be attached here.',
            'photo.max' => 'Photos must be 20 MB or smaller.',
        ]);

        $file = $data['photo'];
        $path = $file->store("job-attachments/{$job->id}", 'local');

        $attachment = $job->attachments()->create([
            'user_id' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
        ]);

        $job->recordActivity('attachment_added', "Photo “{$attachment->name}” added from the field", [
            'attachment_id' => $attachment->id,
        ]);

        return $this->created($this->present($request, $job, $attachment->load('uploader:id,name')), 'Photo added.');
    }

    public function show(Request $request, Job $job, JobAttachment $attachment): StreamedResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_unless($attachment->job_id === $job->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime ?: 'image/jpeg',
        ]);
    }

    public function destroy(Request $request, Job $job, JobAttachment $attachment): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');
        abort_unless($attachment->job_id === $job->id, 404);
        abort_if($job->isLocked(), 409, 'This job is completed and locked.');
        abort_unless(
            $attachment->user_id === $request->user()->id || $request->user()->hasForemanAuthority(),
            403,
            'Only whoever added a photo, or a foreman, can remove it.',
        );

        $attachment->deleteWithFile();

        return $this->ok(null, 'Photo removed.');
    }

    /** @return array<string, mixed> */
    private function present(Request $request, Job $job, JobAttachment $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'url' => route('api.v1.jobs.photos.show', [$job->id, $a->id]),
            'mimeType' => $a->mime,
            'sizeBytes' => $a->size,
            'uploadedBy' => $a->uploader?->name,
            'mine' => $a->user_id === $request->user()->id,
            'createdAt' => $a->created_at?->toISOString(),
        ];
    }
}
