<?php

namespace App\Services\Team;

use App\Models\Foreman;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one person's identity the same everywhere it is stored.
 *
 * A crew member lives in up to three tables — the `users` account that signs
 * in (web and mobile), the `team_members` row time/tasks hang off, and the
 * `foremen` register row the web Teams screen edits — plus the name copied
 * onto each `job_assignments` row. They are linked by `user_id`. Editing the
 * person in any one of them must show up in the others, so the web and the
 * app never disagree about who someone is.
 *
 * Every write here goes through the query builder or `*Quietly`, so the model
 * observers that call this cannot call back into it.
 *
 * Phone and email are deliberately only copied *from* the account *to* the
 * register: on the account they are OTP-verified sign-in identifiers, and an
 * edit on a register row must not silently change how someone logs in.
 */
class MemberIdentitySync
{
    /** Register role ⇄ account role label. */
    private const ROLE_LABELS = [
        Foreman::ROLE_FOREMAN => 'Foreman',
        Foreman::ROLE_JOURNEYMAN => 'Journeyman',
        Foreman::ROLE_APPRENTICE => 'Apprentice',
    ];

    /** A register row was created or edited — push it onto the linked account and crew record. */
    public function fromForeman(Foreman $foreman): void
    {
        if ($foreman->user_id === null) {
            return;
        }

        $user = User::query()->find($foreman->user_id);

        if ($user === null) {
            return;
        }

        $initials = User::initialsFor($foreman->name);
        $label = self::ROLE_LABELS[$foreman->role] ?? null;
        $userChanges = ['name' => $foreman->name, 'initials' => $initials];

        // Only a crew account follows the register's role: a manager or admin
        // who happens to have a register row keeps their own role.
        if ($label !== null && in_array($user->role, self::ROLE_LABELS, true)) {
            $userChanges['role'] = $label;
        }

        $user->forceFill($userChanges)->saveQuietly();

        $memberChanges = ['name' => $foreman->name, 'initials' => $initials, 'team_id' => $foreman->team_id];
        if ($label !== null) {
            $memberChanges['role'] = $label;
        }

        TeamMember::query()->where('user_id', $user->id)->update($memberChanges);

        $this->renameAssignments($user->id, $foreman->name);
    }

    /** An account was edited (profile, web or app) — push it onto the register and crew record. */
    public function fromUser(User $user): void
    {
        $initials = User::initialsFor($user->name);

        $register = ['name' => $user->name, 'initials' => $initials, 'phone' => $user->phone];
        // The register's own email is optional and free-form; only overwrite
        // it with a real account address.
        if (filled($user->email)) {
            $register['email'] = $user->email;
        }

        $foreman = Foreman::query()->where('user_id', $user->id)->first();
        if ($foreman !== null) {
            $foreman->forceFill($register);
            $role = array_search($user->role, self::ROLE_LABELS, true);
            if ($role !== false) {
                $foreman->role = $role;
            }
            $foreman->saveQuietly();
        }

        $member = ['name' => $user->name, 'initials' => $initials];
        if (in_array($user->role, self::ROLE_LABELS, true)) {
            $member['role'] = $user->role;
        }
        TeamMember::query()->where('user_id', $user->id)->update($member);

        $this->renameAssignments($user->id, $user->name);
    }

    /** A crew record was renamed on its own — carry it to the assignment snapshots. */
    public function fromTeamMember(TeamMember $member): void
    {
        DB::table('job_assignments')
            ->where('team_member_id', $member->id)
            ->update(['name' => $member->name]);
    }

    /** `job_assignments.name` is a copy taken at assignment time; refresh it. */
    private function renameAssignments(int $userId, string $name): void
    {
        DB::table('job_assignments')
            ->where('user_id', $userId)
            ->orWhereIn('team_member_id', TeamMember::query()->where('user_id', $userId)->select('id'))
            ->update(['name' => $name]);
    }
}
