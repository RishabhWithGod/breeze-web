<?php

namespace App\Services\Notifications;

use App\Models\Job;
use App\Models\JobApprenticeAssignment;
use App\Models\JobMessage;
use App\Models\User;
use App\Notifications\JobMessagePosted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells everyone else on a job when a message is posted in its conversation:
 * the crew staffed on it (journeymen, foremen over it, apprentices, assigned
 * and task members) and the managers who run it. The sender is never told
 * about their own message, and each person is told once. Never fails the
 * request that posted the message.
 */
class JobMessageNotifier
{
    private const MANAGER_ROLES = ['manager', 'project manager', 'admin', 'owner'];

    public function tell(Job $job, JobMessage $message, User $sender): int
    {
        try {
            $recipients = $this->recipients($job, $sender);

            if ($recipients->isEmpty()) {
                return 0;
            }

            Notification::send($recipients, new JobMessagePosted($job, $message, $sender->name));

            return $recipients->count();
        } catch (Throwable $e) {
            Log::warning('Job message notification could not be delivered', [
                'job_id' => $job->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** @return Collection<int, User> */
    private function recipients(Job $job, User $sender): Collection
    {
        $job->loadMissing('owner', 'foreman.user');

        $apprentices = JobApprenticeAssignment::query()
            ->where('job_id', $job->id)
            ->with('apprentice.user')
            ->get()
            ->map(fn (JobApprenticeAssignment $a) => $a->apprentice?->user);

        $assigned = User::query()
            ->whereIn('id', $job->activeAssignments()->pluck('user_id')->filter())
            ->get();

        $taskMembers = User::query()
            ->whereIn('id', $job->tasks()->with('members')->get()
                ->flatMap(fn ($task) => $task->members->pluck('user_id'))
                ->filter()
                ->unique())
            ->get();

        $owner = $job->owner;
        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?, ?)', self::MANAGER_ROLES)
            ->where(function ($query) use ($owner) {
                $query->when($owner, fn ($q) => $q->where('id', $owner->id))
                    ->when($owner?->company_id, fn ($q, $company) => $q->orWhere('company_id', $company));
            })
            ->get();

        return collect()
            ->merge($job->notifiableForemen())
            ->merge($job->notifiableSupervisors())
            ->push($job->foreman?->user)
            ->merge($apprentices)
            ->merge($assigned)
            ->merge($taskMembers)
            ->merge($managers)
            ->filter()
            ->reject(fn (User $user) => $user->is($sender))
            ->unique(fn (User $user) => $user->getKey())
            ->values();
    }
}
