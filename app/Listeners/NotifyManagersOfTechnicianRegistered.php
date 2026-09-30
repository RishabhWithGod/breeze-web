<?php

namespace App\Listeners;

use App\Events\TechnicianRegistered;
use App\Models\User;
use App\Notifications\TechnicianSignupReceived;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Notification;

/**
 * Tells whoever can approve a technician that one is waiting.
 *
 * Same shape as `NotifyManagersOfTimeEntrySubmitted` — there is no per-team
 * "who approves signups" record, so every Project Manager/Admin/Owner is
 * told.
 */
class NotifyManagersOfTechnicianRegistered implements ShouldHandleEventsAfterCommit
{
    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function handle(TechnicianRegistered $event): void
    {
        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            // Only the company they applied to decides on them.
            ->when(
                $event->technician->company_id !== null,
                fn ($query) => $query->where('company_id', $event->technician->company_id),
                fn ($query) => $query->whereNull('company_id'),
            )
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        Notification::send($managers, new TechnicianSignupReceived($event->technician));
    }
}
