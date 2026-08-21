<?php

use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * Who may listen on which private channel.
 *
 * These mirror the same authorization the corresponding HTTP page already
 * enforces — a channel never grants access a controller would refuse:
 *
 *   - `user.{id}`    — ProjectPolicy-free: notifications belong to exactly
 *                       one person, so only that person may ever subscribe.
 *   - `project.{id}` — the same rule as `ProjectPolicy::view()`: a takeoff
 *                       project belongs to whoever uploaded it.
 *   - `job.{id}`      — the same rule the Job Detail route enforces: any
 *                       signed-in user may view a job (jobs are shared
 *                       company-wide, unlike projects), so the channel only
 *                       has to confirm the job still exists.
 *
 * Cost-sensitive fields are never gated here — that would mean the socket
 * payload itself would have to change per-subscriber, which broadcasting
 * cannot do. Job Costing values are redacted at the point the event's
 * payload is built (see JobStatusChanged, JobCostSummary::redact()),
 * exactly like the HTTP responses already do.
 */
Broadcast::channel('user.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('project.{id}', function (User $user, int $id) {
    return Project::where('id', $id)->where('user_id', $user->id)->exists();
});

Broadcast::channel('job.{id}', function (User $user, int $id) {
    return Job::whereKey($id)->exists();
});
