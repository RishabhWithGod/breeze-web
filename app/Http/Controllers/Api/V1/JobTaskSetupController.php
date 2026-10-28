<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Notifications\TaskScheduleChanged;
use App\Services\Scheduling\ScheduleBuilder;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\CompanyRule;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Breaking a job into tasks, straight after it is created — mobile's
 * counterpart to web's `JobTaskSetupController::create()`/`store()`. A task
 * is built from the job's own estimate lines, not typed from nothing, so
 * `options()` sends every claimable line plus who can be staffed on it
 * (narrowed to the job's own crew, same as web); `store()` writes the whole
 * plan in one all-or-nothing call.
 */
class JobTaskSetupController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ScheduleBuilder $builder,
        private readonly TakeoffFlow $flow,
    ) {}

    public function options(Request $request, Job $job): JsonResponse
    {
        $this->authorise($request, $job);

        // The last step, and still a step: remembered so leaving it
        // mid-way leaves a way back, same as web's own `create()` does.
        if ($job->project !== null) {
            $this->flow->remember($job->project, $request->user());
        }

        $job->loadMissing(['schedule.tasks.foreman', 'schedule.tasks.crew', 'schedule.tasks.estimateItems']);

        return $this->ok([
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                'fromTakeoff' => $job->ai_result_id !== null,
                // The takeoff (project) this job was raised from — the step before.
                'takeoffId' => $job->ai_result_id !== null ? $job->project_id : null,
                'isLocked' => $job->isLocked(),
            ],
            'estimateLines' => $this->lines($job),
            'existingTasks' => $job->schedule?->tasks->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                // Everyone the task is given to, by name.
                'foreman' => $task->crew->pluck('name')->unique()->implode(', ') ?: $task->foreman?->name,
                'supervisor' => $task->supervisor?->name,
                'lineCount' => $task->estimateItems->count(),
            ])->values() ?? [],
            ...$this->staffing($job),
        ]);
    }

    /** Records the whole task list in one write — all or nothing. */
    public function store(Request $request, Job $job): JsonResponse
    {
        $this->authorise($request, $job);
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be changed.');

        $hasLines = $this->estimateLineCount($job) > 0;

        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            // Everyone the task is given to: journeymen run it, foremen are over it.
            'tasks.*.member_ids' => ['required', 'array', 'min:1', 'max:30'],
            'tasks.*.member_ids.*' => ['integer', CompanyRule::exists('foremen')],
            'tasks.*.estimate_item_ids' => $hasLines
                ? ['required', 'array', 'min:1', 'max:200']
                : ['nullable', 'array', 'max:200'],
            'tasks.*.estimate_item_ids.*' => ['integer'],
        ], [
            'tasks.required' => 'A job needs at least one task.',
            'tasks.*.title.required' => 'Give the task a name, or remove the row.',
            'tasks.*.member_ids.required' => 'Pick the members this task is for.',
            'tasks.*.member_ids.min' => 'Pick the members this task is for.',
            'tasks.*.estimate_item_ids.required' => 'Pick the estimate lines this task covers.',
            'tasks.*.estimate_item_ids.min' => 'Pick the estimate lines this task covers.',
        ]);

        // Each row's people against the project's members / the job's crew,
        // then split into who runs the task and who is over it.
        $crews = [];
        foreach ($data['tasks'] as $index => $row) {
            $this->refuseOffCrew($job, $row['member_ids'], "tasks.{$index}.member_ids");
            $crews[$index] = $this->splitCrew($row['member_ids'], "tasks.{$index}.member_ids");
        }

        $this->rejectDuplicateTitles($job->schedule?->tasks()->pluck('title') ?? collect(), $data['tasks']);

        $claimable = $this->claimableLines($job);
        $this->rejectUnavailableLines($claimable, $data['tasks']);

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user(), withTasks: false);
        $createdTasks = [];

        DB::transaction(function () use ($schedule, $job, $request, $data, $crews, &$createdTasks) {
            $position = (int) $schedule->tasks()->max('position');

            foreach ($data['tasks'] as $index => $row) {
                $position++;

                $laborLineIds = $row['estimate_item_ids'] ?? [];
                $lineIds = array_values(array_unique(array_merge(
                    $laborLineIds,
                    $this->pairedMaterialLines($job, $laborLineIds),
                )));

                $task = $schedule->tasks()->create([
                    'job_id' => $job->id,
                    'created_by' => $request->user()?->id,
                    'title' => trim($row['title']),
                    'foreman_id' => $crews[$index]['runners'][0] ?? null,
                    'supervisor_id' => $crews[$index]['overseers'][0] ?? null,
                    'estimated_hours' => $this->hoursOn($lineIds),
                    'priority' => 'medium',
                    'status' => JobTask::STATUS_PENDING,
                    'position' => $position,
                ]);

                // The whole crew, not just the first journeyman and foreman.
                $task->assignCrew($crews[$index]['runners'], $crews[$index]['overseers']);

                if ($lineIds !== []) {
                    EstimateItem::whereIn('id', $lineIds)->update(['job_task_id' => $task->id]);
                }

                $createdTasks[] = $task;
            }

            // Dates, dependency chain and the schedule's own window, laid out
            // from what was just created — and the crew booked for those days.
            $this->builder->scheduleTasks($schedule->refresh());
            $this->builder->bookCrew($job, $schedule);

            $job->refreshEstimatedHours();
        });

        // A freshly-created task's people are always newly assigned.
        foreach ($createdTasks as $task) {
            $this->notifyAssignment($task);
        }

        $count = count($data['tasks']);

        $job->recordActivity('tasks_added', $count.' '.str('task')->plural($count).' added to the schedule');

        // The takeoff has become a job with its work laid out. Nothing
        // left to resume, so the floating button goes — same as web's own
        // `store()` calling `TakeoffFlow::forget()` here. Only clears this
        // user's own pointer, and only if it was still pointing at this job.
        if ($job->project_id !== null
            && $request->user()->takeoff_flow_project_id === $job->project_id) {
            $this->flow->forget($request->user());
        }

        return $this->created([
            'jobId' => $job->id,
            'taskIds' => collect($createdTasks)->pluck('id')->all(),
        ], $count.' '.str('task')->plural($count)." added to \"{$job->name}\".");
    }

    /**
     * One task's own edit form — mobile's counterpart to web's own
     * `JobTaskSetupController::edit()`. Kept on this PM-owned controller
     * rather than reusing `Api\V1\JobTaskController::show()`, which is
     * scoped to the job's own crew (`ElectricianJobAccess`) — a manager
     * editing a task they planned is not necessarily staffed on the job
     * themselves.
     */
    public function editOptions(Request $request, JobTask $task): JsonResponse
    {
        $job = $task->job;
        $this->authorise($request, $job);

        return $this->ok([
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'isLocked' => $job->isLocked(),
            ],
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'memberIds' => $task->runnerIds()->merge($task->overseerIds())->unique()->map(fn ($id) => (int) $id)->values(),
                'isCompleted' => $task->status === JobTask::STATUS_COMPLETED,
                'lineIds' => $task->estimateItems()
                    ->where('category', EstimateItem::CATEGORY_LABOR)
                    ->pluck('id')
                    ->values(),
            ],
            'estimateLines' => $this->lines($job, $task),
            'statuses' => JobTask::STATUSES,
            ...$this->staffing($job),
        ]);
    }

    /**
     * One existing task, corrected — mobile's counterpart to web's own
     * `JobTaskSetupController::update()`. Deliberately the full admin
     * shape (title/status/foreman/supervisor/estimate lines), reusing the
     * exact same helpers `store()` already leans on, rather than the
     * narrower status-only/progress-only actions `Api\V1\JobTaskController`
     * otherwise scopes mobile to.
     */
    public function update(Request $request, JobTask $task): JsonResponse
    {
        $job = $task->job;
        $this->authorise($request, $job);
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be changed.');
        abort_if($task->status === JobTask::STATUS_COMPLETED, 409, 'This task is already completed and can no longer be changed.');

        $hasLines = $this->estimateLineCount($job) > 0;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'status' => ['required', Rule::in(JobTask::STATUSES)],
            'member_ids' => ['required', 'array', 'min:1', 'max:30'],
            'member_ids.*' => ['integer', CompanyRule::exists('foremen')],
            'estimate_item_ids' => $hasLines
                ? ['required', 'array', 'min:1', 'max:200']
                : ['nullable', 'array', 'max:200'],
            'estimate_item_ids.*' => ['integer'],
        ], [
            'title.required' => 'Give the task a name.',
            'member_ids.required' => 'Pick the members this task is for.',
            'member_ids.min' => 'Pick the members this task is for.',
            'estimate_item_ids.required' => 'Pick the estimate lines this task covers.',
            'estimate_item_ids.min' => 'Pick the estimate lines this task covers.',
        ]);

        $this->refuseOffCrew($job, $data['member_ids']);
        $crew = $this->splitCrew($data['member_ids'], 'member_ids');

        $title = trim($data['title']);

        $clash = JobTask::query()
            ->where('job_id', $job->id)
            ->whereKeyNot($task->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['title' => 'This job already has a task with that name.']);
        }

        $laborIds = array_values(array_unique(array_map(intval(...), $data['estimate_item_ids'] ?? [])));

        // Free, or already this task's own — anything else belongs elsewhere.
        $allowed = EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->where(fn ($query) => $query->whereNull('job_task_id')->orWhere('job_task_id', $task->id))
            ->pluck('id')
            ->all();

        if (array_diff($laborIds, $allowed) !== []) {
            throw ValidationException::withMessages([
                'estimate_item_ids' => 'One of those lines is already planned into another task. Reload and try again.',
            ]);
        }

        $ids = array_values(array_unique(array_merge(
            $laborIds,
            $this->pairedMaterialLines($job, $laborIds, $task->id),
        )));

        // Who was on the task before, so only people newly given it are told.
        $before = $task->runnerIds()->merge($task->overseerIds())->unique();

        DB::transaction(function () use ($task, $job, $title, $data, $ids, $crew) {
            $task->update([
                'title' => $title,
                'status' => $data['status'],
                'estimated_hours' => $this->hoursOn($ids),
            ]);

            $task->assignCrew($crew['runners'], $crew['overseers']);

            // Dropped lines go back into the picker for another task to take.
            EstimateItem::query()
                ->where('job_task_id', $task->id)
                ->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))
                ->update(['job_task_id' => null]);

            if ($ids !== []) {
                EstimateItem::whereIn('id', $ids)->update(['job_task_id' => $task->id]);
            }

            $job->refreshEstimatedHours();
        });

        $added = $task->runnerIds()->merge($task->overseerIds())->unique()->diff($before)->values();
        if ($added->isNotEmpty()) {
            $this->notifyAssignment($task, $added);
        }

        return $this->ok(
            $this->present($task->fresh(['foreman', 'supervisor', 'estimateItems'])),
            "\"{$task->title}\" was updated.",
        );
    }

    /**
     * Removes one task from the plan — mobile's counterpart to web's own
     * `JobTaskSetupController::destroy()`. Its estimate lines go back
     * unclaimed for another task to take, same as web.
     */
    public function destroy(Request $request, JobTask $task): JsonResponse
    {
        $job = $task->job;
        $this->authorise($request, $job);
        abort_if($job->isLocked(), 409, 'This job is already completed and can no longer be changed.');
        abort_if($task->status === JobTask::STATUS_COMPLETED, 409, 'This task is already completed and can no longer be removed.');

        $title = $task->title;

        DB::transaction(function () use ($task, $job) {
            EstimateItem::where('job_task_id', $task->id)->update(['job_task_id' => null]);
            $task->delete();
            $job->refreshEstimatedHours();
        });

        $job->recordActivity('task_deleted', "Task removed: {$title}");

        return $this->ok(null, "\"{$title}\" was removed from \"{$job->name}\".");
    }

    /** @return array<string, mixed> */
    private function present(JobTask $task): array
    {
        return [
            'id' => $task->id,
            'jobId' => $task->job_id,
            'title' => $task->title,
            'status' => $task->status,
            'memberIds' => $task->runnerIds()->merge($task->overseerIds())->unique()->map(fn ($id) => (int) $id)->values(),
            'foremanName' => $task->foreman?->name,
            'supervisorName' => $task->supervisor?->name,
            'estimatedHours' => $task->estimated_hours === null ? null : (float) $task->estimated_hours,
            'estimateItemIds' => $task->estimateItems->pluck('id')->values(),
        ];
    }

    /**
     * Who a task on this job can be given to — the job's project members when
     * its project has any picked, otherwise the job's team, otherwise the
     * whole register. Journeymen run the work and foremen are over it; which
     * side someone lands on follows their role. Apprentices are never offered.
     *
     * @return array<string, mixed>
     */
    private function staffing(Job $job): array
    {
        $team = $job->team;
        $projectMemberIds = $job->projectMemberIds();

        if ($projectMemberIds !== null) {
            $journeymen = Foreman::assignable()->whereIn('id', $projectMemberIds)->where('role', Foreman::ROLE_JOURNEYMAN)->orderBy('name');
            $supervisors = Foreman::assignable()->whereIn('id', $projectMemberIds)->where('role', Foreman::ROLE_FOREMAN)->orderBy('name');
        } elseif ($team !== null) {
            $journeymen = $team->journeymen()->assignable();
            $supervisors = $team->foremen()->assignable();
        } else {
            $journeymen = Foreman::assignable()->where('role', Foreman::ROLE_JOURNEYMAN)->orderBy('name');
            $supervisors = Foreman::assignable()->where('role', Foreman::ROLE_FOREMAN)->orderBy('name');
        }

        $runners = $journeymen->get(['id', 'name', 'initials', 'role']);
        $overseers = $supervisors->get(['id', 'name', 'initials', 'role']);

        return [
            'members' => $runners->concat($overseers)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                ->map(fn (Foreman $person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials,
                    'role' => $person->role,
                    'roleLabel' => $person->roleLabel(),
                ])->all(),
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function lines(Job $job, ?JobTask $editing = null): array
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->with('task:id,title')
            ->orderBy('estimate_id')
            ->orderBy('position')
            ->get()
            ->map(fn (EstimateItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'category' => $item->category,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'total' => (float) $item->total,
                'taskId' => $item->job_task_id === $editing?->id ? null : $item->job_task_id,
                'taskTitle' => $item->job_task_id === $editing?->id ? null : $item->task?->title,
            ])->all();
    }

    /** @return Collection<int, int> */
    private function estimateIds(Job $job): Collection
    {
        $own = $job->estimates()->pluck('id');

        if ($job->ai_result_id === null) {
            return $own;
        }

        return $own->merge(Estimate::where('ai_result_id', $job->ai_result_id)->pluck('id'))->unique()->values();
    }

    private function hoursOn(array $lineIds): ?float
    {
        if ($lineIds === []) {
            return null;
        }

        $hours = (float) EstimateItem::whereIn('id', $lineIds)
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->sum('quantity');

        return $hours > 0 ? round($hours, 2) : null;
    }

    private function estimateLineCount(Job $job): int
    {
        return EstimateItem::whereIn('estimate_id', $this->estimateIds($job))->count();
    }

    /** @return Collection<int, int> */
    private function claimableLines(Job $job): Collection
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->whereNull('job_task_id')
            ->pluck('id');
    }

    /** @return list<int> */
    private function pairedMaterialLines(Job $job, array $laborLineIds, ?int $editingTaskId = null): array
    {
        if ($laborLineIds === []) {
            return [];
        }

        $estimateIds = $this->estimateIds($job);

        $symbolIds = EstimateItem::query()
            ->whereIn('id', $laborLineIds)
            ->whereIn('estimate_id', $estimateIds)
            ->whereNotNull('final_symbol_id')
            ->pluck('final_symbol_id')
            ->unique()
            ->all();

        if ($symbolIds === []) {
            return [];
        }

        return EstimateItem::query()
            ->whereIn('estimate_id', $estimateIds)
            ->whereIn('final_symbol_id', $symbolIds)
            ->where('category', '!=', EstimateItem::CATEGORY_LABOR)
            ->where(function ($query) use ($editingTaskId) {
                $query->whereNull('job_task_id');

                if ($editingTaskId !== null) {
                    $query->orWhere('job_task_id', $editingTaskId);
                }
            })
            ->pluck('id')
            ->all();
    }

    /**
     * Refuses anyone {@see staffing()} does not offer — the project's own
     * members when it has any, the job's crew otherwise.
     *
     * @param  array<int, int|null>  $ids
     */
    private function refuseOffCrew(Job $job, array $ids, string $errorKey = 'member_ids'): void
    {
        $given = array_values(array_filter($ids));

        if ($given === []) {
            return;
        }

        $projectMemberIds = $job->projectMemberIds();

        if ($projectMemberIds !== null) {
            if (array_diff($given, $projectMemberIds->all()) !== []) {
                throw ValidationException::withMessages([$errorKey => 'That person is not staffed to this project.']);
            }

            return;
        }

        $team = $job->team;

        if ($team === null) {
            return;
        }

        $onCrew = Foreman::whereKey($given)->where('team_id', $team->id)->pluck('id')->all();

        if (array_diff($given, $onCrew) !== []) {
            throw ValidationException::withMessages([$errorKey => "That person is not on {$team->name}."]);
        }
    }

    /**
     * Splits the people picked into who runs the task (journeymen) and who is
     * over it (foremen). One of either is enough; an apprentice is refused.
     *
     * @param  array<int, int|string>  $memberIds
     * @return array{runners: list<int>, overseers: list<int>}
     */
    private function splitCrew(array $memberIds, string $errorKey): array
    {
        $people = Foreman::query()->whereKey($memberIds)->get()->keyBy('id');
        $runners = [];
        $overseers = [];

        foreach (array_unique(array_map('intval', $memberIds)) as $id) {
            $person = $people->get($id);

            if ($person === null) {
                continue;
            }

            if ($person->role === Foreman::ROLE_JOURNEYMAN) {
                $runners[] = $id;
            } elseif ($person->role === Foreman::ROLE_FOREMAN) {
                $overseers[] = $id;
            } else {
                throw ValidationException::withMessages([
                    $errorKey => "{$person->name} is an apprentice. Apprentices go on the job under a journeyman, not on a task.",
                ]);
            }
        }

        if ($runners === [] && $overseers === []) {
            throw ValidationException::withMessages([$errorKey => 'Pick at least one journeyman or foreman for this task.']);
        }

        return ['runners' => $runners, 'overseers' => $overseers];
    }

    /**
     * Tells whoever is newly running or overseeing a task.
     *
     * @param  ?Collection<int, int>  $only  Foreman ids to tell; everyone on the task when null.
     */
    private function notifyAssignment(JobTask $task, ?Collection $only = null): void
    {
        $ids = $only ?? $task->runnerIds()->merge($task->overseerIds())->unique();

        Foreman::query()->with('user')->whereKey($ids)->get()
            ->map(fn (Foreman $person) => $person->user)
            ->filter()
            ->unique('id')
            ->each(fn ($user) => $user->notify(new TaskScheduleChanged($task, TaskScheduleChanged::ASSIGNED)));
    }

    /**
     * @param  Collection<int, string>  $existing
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function rejectDuplicateTitles(Collection $existing, array $tasks): void
    {
        $seen = $existing->map(fn (string $title) => mb_strtolower(trim($title)))->all();
        $errors = [];

        foreach ($tasks as $index => $task) {
            $title = mb_strtolower(trim((string) $task['title']));

            if (in_array($title, $seen, true)) {
                $errors["tasks.{$index}.title"] = 'This job already has a task with that name.';

                continue;
            }

            $seen[] = $title;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  Collection<int, int>  $claimable
     * @param  array<int, array<string, mixed>>  $tasks
     */
    private function rejectUnavailableLines(Collection $claimable, array $tasks): void
    {
        $free = $claimable->all();
        $errors = [];

        foreach ($tasks as $index => $task) {
            foreach ($task['estimate_item_ids'] ?? [] as $id) {
                $at = array_search($id, $free, true);

                if ($at === false) {
                    $errors["tasks.{$index}.estimate_item_ids"] = 'One of those lines is already planned into another task. Reload the page.';

                    break;
                }

                unset($free[$at]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function authorise(Request $request, Job $job): void
    {
        abort_unless(Ownership::owns($request->user(), $job->user_id), 403);
    }
}
