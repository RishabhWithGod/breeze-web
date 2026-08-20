<?php

namespace App\Listeners;

use App\Events\TimeEntrySubmitted;
use App\Models\User;
use App\Notifications\TimeEntryStatusChanged;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Tells whoever can approve time that one is waiting.
 *
 * There is no per-job "who is the approver" record, so every Project
 * Manager/Admin/Owner is told — a small crew, one approval queue.
 */
class NotifyManagersOfTimeEntrySubmitted implements ShouldHandleEventsAfterCommit
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function handle(TimeEntrySubmitted $event): void
    {
        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->where('id', '!=', $event->entry->user_id)
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        Notification::send($managers, new TimeEntryStatusChanged($event->entry, TimeEntryStatusChanged::SUBMITTED));
    }
}
