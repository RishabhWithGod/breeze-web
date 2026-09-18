<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * Who may do what to a time entry.
 *
 * Built on `users.role`, the same free-text column `JobSchedulePolicy` already
 * matches case-insensitively — and, for anyone reading time that is not their
 * own, on whether the entry's job is theirs to manage at all. Anyone can log
 * and submit their own time; a Foreman/Journeyman/Apprentice can see their
 * crew's time on their own jobs but not approve it; only a Project Manager/
 * Admin/Owner can approve, reject, see job costs, or run reports, and only for
 * jobs they manage; only an Admin/Owner can change the module's overtime
 * settings.
 */
class TimeEntryPolicy
{
    /** Roles that may see a crew's time without owning it. */
    private const FOREMEN = ['foreman', 'journeyman', 'apprentice'];

    /** Roles that may approve, reject, see costs and run reports. */
    private const MANAGERS = ['project manager', 'admin', 'owner'];

    /** Roles that may change the module's business rules. */
    private const ADMINS = ['admin', 'owner'];

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id
            || (($this->holds($user, self::FOREMEN) || $this->holds($user, self::MANAGERS)) && $entry->job?->user_id === $user->id);
    }

    /** Anyone signed in can log time — for themselves, against a job they can see. */
    public function create(User $user): bool
    {
        return true;
    }

    /** Only the owner, and only while it can still be changed directly. */
    public function update(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id && $entry->isEditable();
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id && $entry->isEditable();
    }

    public function submit(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id && $entry->isEditable();
    }

    /** Foremen see their crew's time; they do not decide it. */
    public function viewCrew(User $user): bool
    {
        return $this->holds($user, self::FOREMEN) || $this->holds($user, self::MANAGERS);
    }

    public function approve(User $user, TimeEntry $entry): bool
    {
        return $this->holds($user, self::MANAGERS) && $entry->job?->user_id === $user->id
            && $entry->status === TimeEntry::STATUS_SUBMITTED;
    }

    public function reject(User $user, TimeEntry $entry): bool
    {
        return $this->holds($user, self::MANAGERS) && $entry->job?->user_id === $user->id
            && $entry->status === TimeEntry::STATUS_SUBMITTED;
    }

    /**
     * Opens an approved entry for a correction — never edits it in place.
     *
     * Only an `approved` row may be reopened, not one already `locked`: that
     * one has already been superseded, and its correction is what should be
     * reopened instead if it also needs fixing.
     */
    public function reopen(User $user, TimeEntry $entry): bool
    {
        return $this->holds($user, self::MANAGERS) && $entry->job?->user_id === $user->id
            && $entry->status === TimeEntry::STATUS_APPROVED;
    }

    public function viewJobCosts(User $user, ?Job $job = null): bool
    {
        return $this->holds($user, self::MANAGERS) && ($job === null || $job->user_id === $user->id);
    }

    public function viewReports(User $user): bool
    {
        return $this->holds($user, self::MANAGERS);
    }

    public function manageSettings(User $user): bool
    {
        return $this->holds($user, self::ADMINS);
    }

    /** Derived so the client can hide what it cannot do, rather than fail on submit. */
    public function abilities(User $user): array
    {
        return [
            'viewCrew' => $this->viewCrew($user),
            'approve' => $this->holds($user, self::MANAGERS),
            'viewJobCosts' => $this->viewJobCosts($user),
            'viewReports' => $this->viewReports($user),
            'manageSettings' => $this->manageSettings($user),
        ];
    }

    /* ------------------------------------------------------------- internals */

    /** @param  list<string>  $roles */
    private function holds(User $user, array $roles): bool
    {
        return in_array(mb_strtolower(trim((string) $user->role)), $roles, true);
    }
}
