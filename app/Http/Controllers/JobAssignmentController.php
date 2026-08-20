<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\JobAssignment;
use App\Models\TeamMember;
use App\Notifications\JobAssigned;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staffing a job by role: estimator, project manager, foreman, electrician,
 * reviewer.
 *
 * Releasing someone keeps the row and stamps `released_at`, so the panel can show
 * the full assignment history alongside who is on the job today.
 */
class JobAssignmentController extends Controller
{
    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in(JobAssignment::ROLES)],
            'team_member_id' => ['nullable', 'integer', 'exists:team_members,id'],
            'name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $member = filled($validated['team_member_id'] ?? null)
            ? TeamMember::find($validated['team_member_id'])
            : null;

        $name = $member?->name ?? trim((string) ($validated['name'] ?? ''));

        if (blank($name)) {
            return back()->withErrors([
                'name' => 'Choose a crew member or type a name.',
            ]);
        }

        // One holder per role: whoever was there is released first.
        $replaced = $job->activeAssignments()->where('role', $validated['role'])->get();

        foreach ($replaced as $previous) {
            $previous->update(['released_at' => now()]);
        }

        $assignment = $job->assignments()->create([
            'team_member_id' => $member?->id,
            'user_id' => $member?->user_id,
            'role' => $validated['role'],
            'name' => $name,
            'notes' => $validated['notes'] ?? null,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
        ]);

        $job->recordActivity(
            'assigned',
            "{$name} assigned as {$assignment->roleLabel()}"
                .($replaced->isNotEmpty() ? ", replacing {$replaced->first()->name}" : ''),
            ['assignment_id' => $assignment->id, 'role' => $assignment->role],
        );

        $job->aiResult?->recordHistory(
            'assignment_added',
            "{$name} assigned as {$assignment->roleLabel()} on “{$job->name}”",
            to: $name,
            meta: ['job_id' => $job->id, 'role' => $assignment->role],
        );

        if ($assignment->user_id && $assignment->user_id !== $request->user()->id) {
            $assignment->user->notify(new JobAssigned($assignment));
        }

        return back()->with('success', "{$name} assigned as {$assignment->roleLabel()}.");
    }

    /** Releases an assignment, keeping it in the history. */
    public function destroy(Job $job, JobAssignment $assignment): RedirectResponse
    {
        abort_unless($assignment->job_id === $job->id, 404);

        if (! $assignment->isActive()) {
            return back();
        }

        $assignment->update(['released_at' => now()]);

        $job->recordActivity(
            'unassigned',
            "{$assignment->name} released from {$assignment->roleLabel()}",
            ['assignment_id' => $assignment->id, 'role' => $assignment->role],
        );

        $job->aiResult?->recordHistory(
            'assignment_released',
            "{$assignment->name} released from {$assignment->roleLabel()} on “{$job->name}”",
            from: $assignment->name,
            meta: ['job_id' => $job->id, 'role' => $assignment->role],
        );

        return back()->with('warning', "{$assignment->name} released from {$assignment->roleLabel()}.");
    }
}
