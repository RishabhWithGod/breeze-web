<?php

namespace App\Support;

use App\Models\Foreman;
use App\Models\JobTask;
use Illuminate\Support\Facades\DB;
use App\Models\JobTaskAssignment;
use App\Models\TeamMember;

/**
 * How many different people are on a job, read off its tasks.
 *
 * A task has a foreman running it, optionally a supervisor over it, and any
 * number of team members assigned to it — all of them are on the crew. Across a
 * job's tasks the same person turns up again and again, so people are counted
 * once: by their user account when they have one (a foreman and a team member
 * can be the same person), otherwise by their own record.
 */
class JobCrew
{
    /**
     * @param  array<int, int>  $jobIds
     * @return array<int, int> job id => distinct people on its tasks
     */
    public static function countsFor(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        $tasks = JobTask::query()
            ->whereIn('job_id', $jobIds)
            ->get(['id', 'job_id', 'foreman_id', 'supervisor_id']);

        $assignments = JobTaskAssignment::query()
            ->whereIn('job_task_id', $tasks->pluck('id'))
            ->get(['job_task_id', 'team_member_id']);

        // Everyone a task is given to — not just the first journeyman and foreman.
        $crew = DB::table('job_task_foremen')
            ->whereIn('job_task_id', $tasks->pluck('id'))
            ->get(['job_task_id', 'foreman_id'])
            ->groupBy('job_task_id');

        $foremanIdsOn = fn ($task) => collect([$task->foreman_id, $task->supervisor_id])
            ->merge(($crew[$task->id] ?? collect())->pluck('foreman_id'))
            ->filter()
            ->unique();

        $foremanUsers = Foreman::query()
            ->whereIn('id', $tasks->flatMap($foremanIdsOn)->unique())
            ->pluck('user_id', 'id');

        $memberUsers = TeamMember::query()
            ->whereIn('id', $assignments->pluck('team_member_id')->unique())
            ->pluck('user_id', 'id');

        $jobOfTask = $tasks->pluck('job_id', 'id');
        $people = [];

        foreach ($tasks as $task) {
            foreach ($foremanIdsOn($task) as $foremanId) {
                $people[$task->job_id][self::key($foremanUsers[$foremanId] ?? null, 'foreman', $foremanId)] = true;
            }
        }

        foreach ($assignments as $assignment) {
            $jobId = $jobOfTask[$assignment->job_task_id] ?? null;

            if ($jobId !== null) {
                $memberId = $assignment->team_member_id;
                $people[$jobId][self::key($memberUsers[$memberId] ?? null, 'member', $memberId)] = true;
            }
        }

        return array_map('count', $people);
    }

    /**
     * The same people countsFor() counts, by name — for the avatar stack on the job list.
     *
     * @param  array<int, int>  $jobIds
     * @return array<int, list<array{id: string, name: string}>> job id => distinct people on its tasks
     */
    public static function peopleFor(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        $tasks = JobTask::query()->whereIn('job_id', $jobIds)->get(['id', 'job_id', 'foreman_id', 'supervisor_id']);
        $assignments = JobTaskAssignment::query()->whereIn('job_task_id', $tasks->pluck('id'))->get(['job_task_id', 'team_member_id']);
        $crew = DB::table('job_task_foremen')->whereIn('job_task_id', $tasks->pluck('id'))->get(['job_task_id', 'foreman_id'])->groupBy('job_task_id');

        $foremanIdsOn = fn ($task) => collect([$task->foreman_id, $task->supervisor_id])
            ->merge(($crew[$task->id] ?? collect())->pluck('foreman_id'))
            ->filter()
            ->unique();

        $foremen = Foreman::query()->whereIn('id', $tasks->flatMap($foremanIdsOn)->unique())->get(['id', 'user_id', 'name'])->keyBy('id');
        $members = TeamMember::query()->whereIn('id', $assignments->pluck('team_member_id')->unique())->get(['id', 'user_id', 'name'])->keyBy('id');

        $jobOfTask = $tasks->pluck('job_id', 'id');
        $people = [];

        foreach ($tasks as $task) {
            foreach ($foremanIdsOn($task) as $foremanId) {
                $who = $foremen[$foremanId] ?? null;

                if ($who) {
                    $people[$task->job_id][self::key($who->user_id, 'foreman', $foremanId)] = $who->name;
                }
            }
        }

        foreach ($assignments as $assignment) {
            $jobId = $jobOfTask[$assignment->job_task_id] ?? null;
            $who = $members[$assignment->team_member_id] ?? null;

            if ($jobId !== null && $who) {
                $people[$jobId][self::key($who->user_id, 'member', $who->id)] = $who->name;
            }
        }

        return array_map(
            fn (array $byKey) => collect($byKey)->map(fn ($name, $key) => ['id' => $key, 'name' => $name])->values()->all(),
            $people,
        );
    }

    private static function key(?int $userId, string $kind, int $id): string
    {
        return $userId !== null ? "user:{$userId}" : "{$kind}:{$id}";
    }
}
