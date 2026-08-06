<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class JobNoteController extends Controller
{
    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ], [
            'body.required' => 'Write something before saving the note',
        ]);

        $job->notes()->create([
            'user_id' => Auth::id(),
            'body' => $validated['body'],
        ]);

        $job->recordActivity('note_added', 'Note added');

        return back()->with('success', 'Note added.');
    }

    public function destroy(Job $job, JobNote $note): RedirectResponse
    {
        abort_unless($note->job_id === $job->id, 404);

        $note->delete();
        $job->recordActivity('note_deleted', 'Note deleted');

        return back()->with('warning', 'Note deleted.');
    }
}
