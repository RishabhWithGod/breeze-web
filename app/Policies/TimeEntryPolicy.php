<?php

namespace App\Policies;

use App\Models\Job;
use App\Models\JobAttendance;
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

    /**
     * Matches {@see viewCrew()} (no job-ownership check, but the same company) — the Time
     * Log Viewer and the day-detail screen already list any crew member's
     * entries under {@see viewCrew()}'s blanket grant, so requiring job
     * ownership here as well only broke opening an entry that was already
     * visible in that list, with a 403 on tap.
     */
    public function view(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id
            // A crew's time is read within the company, never across companies.
            || ($this->viewCrew($user) && \App\Support\Ownership::worksWith($user, $entry->user_id));
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
        return $this->holds($user, self::MANAGERS) && \App\Support\Ownership::owns($user, $entry->job?->user_id)
            && $this->awaitsDecision($entry);
    }

    /**
     * Closing a check-in nobody closed — a manager, for a job of theirs.
     *
     * The job is read with its deleted ones included: a check-in on a job that has
     * since been removed is exactly the kind left open, and the record still says
     * whose job it was.
     */
    public function closeAttendance(User $user, JobAttendance $attendance): bool
    {
        return $this->holds($user, self::MANAGERS)
            && $attendance->isCheckedIn()
            && \App\Support\Ownership::owns($user, $attendance->job()->withTrashed()->value('user_id'));
    }

    public function reject(User $user, TimeEntry $entry): bool
    {
        return $this->holds($user, self::MANAGERS) && \App\Support\Ownership::owns($user, $entry->job?->user_id)
            && $this->awaitsDecision($entry);
    }

    /**
     * Ready for a manager: submitted, or a draft whose session is over.
     *
     * An employee who has checked in and out has done their part; making them
     * press Submit as well before a manager can act only leaves finished time
     * sitting as a draft. A draft with no end is still running or unfinished, so
     * it is not offered.
     */
    private function awaitsDecision(TimeEntry $entry): bool
    {
        return $entry->status === TimeEntry::STATUS_SUBMITTED
            || ($entry->status === TimeEntry::STATUS_DRAFT && $entry->end_time !== null);
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
        return $this->holds($user, self::MANAGERS) && \App\Support\Ownership::owns($user, $entry->job?->user_id)
            && $entry->status === TimeEntry::STATUS_APPROVED;
    }

    public function viewJobCosts(User $user, ?Job $job = null): bool
    {
        return $this->holds($user, self::MANAGERS) && ($job === null || \App\Support\Ownership::owns($user, $job->user_id));
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
