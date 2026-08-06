<?php

namespace App\Services\Scheduling;

use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\JobTaskDependency;
use Illuminate\Support\Collection;

/**
 * The dependency graph of one schedule: what waits on what, and what that costs.
 *
 * Loaded once and answered from memory. Every question here — is this edge legal, is
 * this task ready, which tasks are on the critical path, which constraints are
 * currently broken — is a graph walk, and doing them one query at a time would mean
 * a query per edge on a screen that shows all of them.
 */
class TaskDependencyGraph
{
    /** @var Collection<int, JobTask> Tasks keyed by id. */
    private Collection $tasks;

    /** @var array<int, list<array{id: int, type: string, lag: int}>> Predecessors per task id. */
    private array $predecessors = [];

    /** @var array<int, list<int>> Successor ids per task id. */
    private array $successors = [];

    private function __construct(Collection $tasks, Collection $edges)
    {
        $this->tasks = $tasks->keyBy('id');

        foreach ($edges as $edge) {
            $this->predecessors[$edge->job_task_id][] = [
                'id' => $edge->depends_on_id,
                'type' => $edge->type,
                'lag' => (int) $edge->lag_days,
            ];
            $this->successors[$edge->depends_on_id][] = $edge->job_task_id;
        }
    }

    /** Builds the graph for a schedule in two queries. */
    public static function for(JobSchedule $schedule): self
    {
        $tasks = JobTask::query()
            ->where('job_schedule_id', $schedule->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $edges = JobTaskDependency::query()
            ->whereIn('job_task_id', $tasks->pluck('id'))
            ->get();

        return new self($tasks, $edges);
    }

    /**
     * Whether adding this edge would create a cycle.
     *
     * A cycle is a schedule that can never start: A waits on B, B waits on A. Checked
     * by walking forward from the proposed successor — if the proposed predecessor is
     * reachable, the edge closes a loop.
     *
     * Self-dependency counts: a task waiting on itself is the shortest cycle there is.
     */
    public function wouldCycle(int $taskId, int $dependsOnId): bool
    {
        if ($taskId === $dependsOnId) {
            return true;
        }

        return $this->reaches($taskId, $dependsOnId);
    }

    /** Depth-first: can `from` reach `target` by following successors? */
    private function reaches(int $from, int $target): bool
    {
        $stack = [$from];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === $target) {
                return true;
            }

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($this->successors[$current] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }

    /**
     * Constraints that are currently broken.
     *
     * A break is a schedule that says two contradictory things — "this cannot start
     * until that finishes" alongside dates where it starts first. It is a warning
     * rather than an error because the dates may be the thing that is wrong, and
     * refusing the save would leave the planner unable to fix either.
     *
     * @return list<array<string, mixed>>
     */
    public function breaches(): array
    {
        $breaches = [];

        foreach ($this->tasks as $task) {
            foreach ($this->predecessors[$task->id] ?? [] as $edge) {
                $predecessor = $this->tasks->get($edge['id']);

                if ($predecessor === null) {
                    continue;
                }

                $problem = $this->breachFor($task, $predecessor, $edge['type'], $edge['lag']);

                if ($problem === null) {
                    continue;
                }

                $breaches[] = [
                    'taskId' => $task->id,
                    'taskTitle' => $task->title,
                    'dependsOnId' => $predecessor->id,
                    'dependsOnTitle' => $predecessor->title,
                    'type' => $edge['type'],
                    'typeLabel' => $this->typeLabel($edge['type']),
                    'lagDays' => $edge['lag'],
                    'problem' => $problem,
                ];
            }
        }

        return $breaches;
    }

    /** The one sentence describing how a specific constraint is violated. */
    private function breachFor(JobTask $task, JobTask $predecessor, string $type, int $lag): ?string
    {
        // Nothing to check until both ends carry the dates the rule is about.
        return match ($type) {
            JobTaskDependency::START_TO_START => $this->compare(
                $predecessor->starts_on,
                $task->starts_on,
                $lag,
                "{$task->title} starts before {$predecessor->title} does",
            ),
            JobTaskDependency::FINISH_TO_FINISH => $this->compare(
                $predecessor->ends_on,
                $task->ends_on,
                $lag,
                "{$task->title} finishes before {$predecessor->title} does",
            ),
            default => $this->compare(
                $predecessor->ends_on,
                $task->starts_on,
                $lag,
                "{$task->title} starts before {$predecessor->title} finishes",
            ),
        };
    }

    private function compare(?object $constraint, ?object $actual, int $lag, string $message): ?string
    {
        if ($constraint === null || $actual === null) {
            return null;
        }

        $earliest = $constraint->copy()->addDays($lag);

        if ($actual->gte($earliest)) {
            return null;
        }

        return $lag > 0
            ? $message." (needs {$lag} ".str('day')->plural($lag).' of lag)'
            : $message;
    }

    /**
     * Tasks whose predecessors are all satisfied but which are still `pending`.
     *
     * The gap between "nothing is stopping this" and "somebody has been told" is
     * where schedules quietly stall, so it is reported rather than left implicit.
     *
     * @return list<int>
     */
    public function readyToStart(): array
    {
        $ready = [];

        foreach ($this->tasks as $task) {
            if ($task->status !== JobTask::STATUS_PENDING) {
                continue;
            }

            if ($this->satisfied($task->id)) {
                $ready[] = $task->id;
            }
        }

        return $ready;
    }

    /**
     * Tasks held up by an unfinished predecessor.
     *
     * @return array<int, list<string>> Blocking task titles, keyed by blocked task id.
     */
    public function blocked(): array
    {
        $blocked = [];

        foreach ($this->tasks as $task) {
            if ($task->isClosed()) {
                continue;
            }

            $blockers = [];

            foreach ($this->predecessors[$task->id] ?? [] as $edge) {
                $predecessor = $this->tasks->get($edge['id']);

                if ($predecessor !== null && ! $this->edgeSatisfied($predecessor, $edge['type'])) {
                    $blockers[] = $predecessor->title;
                }
            }

            if ($blockers !== []) {
                $blocked[$task->id] = $blockers;
            }
        }

        return $blocked;
    }

    /** Whether every predecessor of a task is satisfied. */
    public function satisfied(int $taskId): bool
    {
        foreach ($this->predecessors[$taskId] ?? [] as $edge) {
            $predecessor = $this->tasks->get($edge['id']);

            if ($predecessor !== null && ! $this->edgeSatisfied($predecessor, $edge['type'])) {
                return false;
            }
        }

        return true;
    }

    private function edgeSatisfied(JobTask $predecessor, string $type): bool
    {
        return match ($type) {
            JobTaskDependency::START_TO_START => $predecessor->hasStarted(),
            default => $predecessor->isComplete(),
        };
    }

    /**
     * The critical path: the longest chain of dependent work through the schedule.
     *
     * Longest by duration, not by task count — that is the chain whose slippage moves
     * the finish date, which is the only reason to know it. Cancelled tasks are left
     * out; they cannot delay anything.
     *
     * @return list<int> Task ids on the path, in order.
     */
    public function criticalPath(): array
    {
        $best = [];
        $bestLength = -1;
        $memo = [];

        foreach ($this->tasks as $task) {
            if ($task->status === JobTask::STATUS_CANCELLED) {
                continue;
            }

            [$length, $path] = $this->longestFrom($task->id, $memo);

            if ($length > $bestLength) {
                $bestLength = $length;
                $best = $path;
            }
        }

        return $best;
    }

    /**
     * Longest downstream chain from a task, memoised.
     *
     * The graph is acyclic — `wouldCycle` is what keeps it that way — so a plain
     * depth-first walk with memoisation is enough and cannot loop.
     *
     * @param  array<int, array{0: int, 1: list<int>}>  $memo
     * @return array{0: int, 1: list<int>}
     */
    private function longestFrom(int $taskId, array &$memo): array
    {
        if (isset($memo[$taskId])) {
            return $memo[$taskId];
        }

        $task = $this->tasks->get($taskId);

        if ($task === null) {
            return [0, []];
        }

        $own = max(1, $task->is_milestone ? 0 : $this->durationOf($task));
        $bestLength = 0;
        $bestPath = [];

        foreach ($this->successors[$taskId] ?? [] as $successorId) {
            $successor = $this->tasks->get($successorId);

            if ($successor === null || $successor->status === JobTask::STATUS_CANCELLED) {
                continue;
            }

            [$length, $path] = $this->longestFrom($successorId, $memo);

            if ($length > $bestLength) {
                $bestLength = $length;
                $bestPath = $path;
            }
        }

        $result = [$own + $bestLength, [$taskId, ...$bestPath]];
        $memo[$taskId] = $result;

        return $result;
    }

    /** Calendar days a task spans; estimated hours are the fallback. */
    private function durationOf(JobTask $task): int
    {
        if ($task->starts_on !== null && $task->ends_on !== null) {
            return (int) $task->starts_on->diffInDays($task->ends_on) + 1;
        }

        $hours = (float) ($task->estimated_hours ?? 0);

        return max(1, (int) ceil($hours / 8));
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            JobTaskDependency::START_TO_START => 'Start → Start',
            JobTaskDependency::FINISH_TO_FINISH => 'Finish → Finish',
            default => 'Finish → Start',
        };
    }

    /** @return Collection<int, JobTask> */
    public function tasks(): Collection
    {
        return $this->tasks;
    }
}
