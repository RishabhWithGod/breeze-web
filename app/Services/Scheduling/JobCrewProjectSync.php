<?php

namespace App\Services\Scheduling;

use App\Models\Foreman;
use App\Models\Job;

/**
 * Carries the crew a job was handed to back onto its client and project.
 *
 * A manager may leave the crew and members blank when the client and project
 * are created. Once a job on that project has tasks assigned, the crew it was
 * given is the real staffing, so the client and project pick it up rather than
 * staying empty:
 *
 *  - a client with no team takes the job's team;
 *  - a project with no members takes the job's team members, plus anyone
 *    assigned to its tasks.
 *
 * Only ever fills what is empty. A team or member list somebody chose on
 * purpose is never replaced or extended, so running this again is harmless.
 */
class JobCrewProjectSync
{
    public function sync(Job $job): void
    {
        if ($job->team_id === null) {
            return;
        }

        $project = $job->project;
        $client = $job->clientRecord ?? $project?->clientRecord;

        if ($client !== null && $client->team_id === null) {
            $client->update(['team_id' => $job->team_id]);
        }

        if ($project === null || $project->members()->exists()) {
            return;
        }

        $assigned = $job->tasks()
            ->get(['foreman_id', 'supervisor_id'])
            ->flatMap(fn ($task) => [$task->foreman_id, $task->supervisor_id])
            ->push($job->foreman_id);

        $ids = Foreman::query()
            ->where('team_id', $job->team_id)
            ->pluck('id')
            ->merge($assigned->filter())
            ->unique()
            ->values();

        if ($ids->isNotEmpty()) {
            $project->members()->sync($ids->all());
        }
    }
}
