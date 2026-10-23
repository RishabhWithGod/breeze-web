<?php

namespace App\Services\Notifications;

use App\Models\Job;
use App\Models\User;
use App\Notifications\CrewWorkUpdate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a job's managers when the crew in the field reports work.
 *
 * Only a crew member's own update counts — a manager editing their own job
 * does not need to be told about it, and neither do other managers. Never
 * fails the action that triggered it.
 */
class CrewWorkNotifier
{
    private const CREW_ROLES = ['foreman', 'journeyman', 'apprentice'];

    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    public function tell(Job $job, User $actor, string $title, string $detail, string $type = 'job-crew-work'): int
    {
        if (! in_array(mb_strtolower(trim((string) $actor->role)), self::CREW_ROLES, true)) {
            return 0;
        }

        $owner = $job->owner;

        $recipients = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->whereKeyNot($actor->getKey())
            ->where(function ($query) use ($owner) {
                $query->when($owner, fn ($q) => $q->where('id', $owner->id))
                    ->when($owner?->company_id, fn ($q, $company) => $q->orWhere('company_id', $company));
            })
            ->get();

        if ($recipients->isEmpty()) {
            return 0;
        }

        try {
            Notification::send($recipients, new CrewWorkUpdate($job, $title, $detail, $type));
        } catch (Throwable $e) {
            Log::warning('Crew work notification could not be delivered', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        return $recipients->count();
    }
}
