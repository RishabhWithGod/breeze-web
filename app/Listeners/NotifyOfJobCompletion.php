<?php

namespace App\Listeners;

use App\Events\JobStatusChanged;
use App\Models\User;
use App\Notifications\JobCompleted;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the crew and the office once a job is genuinely finished — the
 * supervisor's own final sign-off, not the crew's earlier "ready for
 * review" step (`JobReviewStatusChanged`, fired separately, earlier in the
 * same workflow, from `Job::markReadyForReview()`).
 *
 * Recipients: the job's foremen and supervisors (via the same
 * `notifiableForemen()`/`notifiableSupervisors()` helpers that workflow
 * already uses), plus every company-wide manager — the same
 * `['project manager', 'admin', 'owner']` role list
 * `NotifyManagersOfTimeEntrySubmitted` already queries, since there is no
 * narrower "this job's own manager" concept in this schema. Whoever
 * actually completed it is excluded — they already know.
 */
class NotifyOfJobCompletion implements ShouldHandleEventsAfterCommit
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function handle(JobStatusChanged $event): void
    {
        if ($event->to !== 'completed') {
            return;
        }

        $job = $event->job;
        $actorId = Auth::id();

        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->get();

        $recipients = $job->notifiableForemen()
            ->merge($job->notifiableSupervisors())
            ->merge($managers)
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actorId);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new JobCompleted($job));
    }
}
