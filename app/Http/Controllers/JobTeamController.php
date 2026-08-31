<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\TeamMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class JobTeamController extends Controller
{
    /** Assigns a crew member to the job. */
    public function store(Request $request, Job $job): RedirectResponse
    {
        $validated = $request->validate([
            'team_member_id' => ['required', 'integer', 'exists:team_members,id'],
            'role_on_job' => ['nullable', 'string', 'max:120'],
        ]);

        $member = TeamMember::findOrFail($validated['team_member_id']);

        if ($job->teamMembers()->whereKey($member->id)->exists()) {
            return back()->with('warning', "{$member->name} is already on this job.");
        }

        $job->teamMembers()->attach($member->id, [
            'role_on_job' => $validated['role_on_job'] ?? null,
        ]);

        $job->recordActivity('team_assigned', "{$member->name} assigned to the job", [
            'team_member_id' => $member->id,
        ]);

        return back()->with('success', "{$member->name} was assigned.");
    }

    /** Removes a crew member from the job. */
    public function destroy(Job $job, TeamMember $member): RedirectResponse
    {
        $job->teamMembers()->detach($member->id);

        $job->recordActivity('team_removed', "{$member->name} removed from the job", [
            'team_member_id' => $member->id,
        ]);

        return back()->with('warning', "{$member->name} was removed.");
    }
}
