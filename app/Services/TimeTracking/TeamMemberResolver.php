<?php

namespace App\Services\TimeTracking;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Resolves which `TeamMember` a signed-in `User` is, creating one if none
 * exists yet.
 *
 * `users` and `team_members` are unrelated tables everywhere else in this app,
 * matched only by name (see `JobSchedulePolicy::isAssigned()`). Time Tracking
 * is the one place that link has to be reliable — a time entry logged "for
 * myself" has to resolve to the same identity a schedule task assignment uses
 * (`job_task_assignments.team_member_id`) — so this closes the gap once, on
 * demand, instead of leaving it a runtime string comparison. Creating a row
 * here records a real person who is really signed in; it is not demo data.
 */
class TeamMemberResolver
{
    public function resolveFor(User $user): TeamMember
    {
        $user->loadMissing('teamMember');

        if ($user->teamMember !== null) {
            return $user->teamMember;
        }

        $match = TeamMember::query()
            ->whereNull('user_id')
            ->whereRaw('lower(trim(name)) = ?', [Str::lower(trim($user->name))])
            ->first();

        if ($match !== null) {
            $match->update(['user_id' => $user->id]);

            return $match;
        }

        return TeamMember::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials,
            'role' => $user->role,
        ]);
    }
}
