<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobAttachmentController extends Controller
{
    /** Stores the uploaded bytes on the local disk and records the row. */
    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ], [
            'file.required' => 'Choose a file to upload',
            'file.max' => 'Files must be 20 MB or smaller',
        ]);

        $file = $validated['file'];
        $path = $file->store("job-attachments/{$job->id}", 'local');

        $attachment = $job->attachments()->create([
            'user_id' => Auth::id(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'size' => $file->getSize(),
            'mime' => $file->getClientMimeType(),
        ]);

        $job->recordActivity('attachment_added', "Attachment “{$attachment->name}” uploaded", [
            'attachment_id' => $attachment->id,
        ]);

        return back()->with('success', "“{$attachment->name}” was uploaded.");
    }

    public function download(Job $job, JobAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->job_id === $job->id, 404);

        return response()->streamDownload(
            fn () => print (Storage::disk('local')->get($attachment->path)),
            $attachment->name,
        );
    }

    public function destroy(Job $job, JobAttachment $attachment): RedirectResponse
    {
        abort_unless($attachment->job_id === $job->id, 404);

        $name = $attachment->name;
        $attachment->deleteWithFile();

        $job->recordActivity('attachment_deleted', "Attachment “{$name}” deleted");

        return back()->with('warning', "“{$name}” was deleted.");
    }
}
