<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobAttachmentController extends Controller
{
    /** Stores the uploaded bytes on the local disk and records the row. */
    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            // Same allowlist Documents uses (content-sniffed by
            // `Rule::file()->extensions()`, not the client-supplied
            // filename) — a job attachment is exactly the same kind of
            // business file, so it gets the same restriction rather than
            // accepting anything under a size cap alone.
            'file' => ['required', Rule::file()->extensions(config('documents.extensions')), 'max:20480'],
        ], [
            'file.required' => 'Choose a file to upload',
            'file.extensions' => 'Unsupported file type — accepted types are '
                .implode(', ', array_map(fn (string $ext) => ".{$ext}", config('documents.extensions'))),
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
