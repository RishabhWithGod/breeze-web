<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function __construct(private readonly ScheduleBuilder $builder) {}

    public function options(Request $request, Job $job): JsonResponse
    {
        $this->authorise($request, $job);

        $job->loadMissing(['schedule.tasks.foreman', 'schedule.tasks.estimateItems']);

        return $this->ok([
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                'fromTakeoff' => $job->ai_result_id !== null,
                'isLocked' => $job->isLocked(),
            ],
            'estimateLines' => $this->lines($job),
            'existingTasks' => $job->schedule?->tasks->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'foreman' => $task->foreman?->name,
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
            'tasks.*.foreman_id' => ['required', 'integer', 'exists:foremen,id'],
            'tasks.*.supervisor_id' => ['required', 'integer', 'exists:foremen,id'],
            'tasks.*.estimate_item_ids' => $hasLines
                ? ['required', 'array', 'min:1', 'max:200']
                : ['nullable', 'array', 'max:200'],
            'tasks.*.estimate_item_ids.*' => ['integer'],
        ], [
            'tasks.required' => 'A job needs at least one task.',
            'tasks.*.title.required' => 'Give the task a name, or remove the row.',
            'tasks.*.foreman_id.required' => 'Pick the foreman running this task.',
            'tasks.*.supervisor_id.required' => 'Pick the supervisor overseeing this task.',
            'tasks.*.estimate_item_ids.required' => 'Pick the estimate lines this task covers.',
            'tasks.*.estimate_item_ids.min' => 'Pick the estimate lines this task covers.',
        ]);

        $this->refuseOffCrew($job, array_merge(
            array_column($data['tasks'], 'foreman_id'),
            array_column($data['tasks'], 'supervisor_id'),
        ));

        $this->rejectDuplicateTitles($job->schedule?->tasks()->pluck('title') ?? collect(), $data['tasks']);

        $claimable = $this->claimableLines($job);
        $this->rejectUnavailableLines($claimable, $data['tasks']);

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user(), withTasks: false);
        $createdTasks = [];

        DB::transaction(function () use ($schedule, $job, $request, $data, &$createdTasks) {
            $position = (int) $schedule->tasks()->max('position');

            foreach ($data['tasks'] as $row) {
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
                    'foreman_id' => $row['foreman_id'] ?? null,
                    'supervisor_id' => $row['supervisor_id'] ?? null,
                    'estimated_hours' => $this->hoursOn($lineIds),
                    'priority' => 'medium',
                    'status' => JobTask::STATUS_PENDING,
                    'position' => $position,
                ]);

                if ($lineIds !== []) {
                    EstimateItem::whereIn('id', $lineIds)->update(['job_task_id' => $task->id]);
                }

                $createdTasks[] = $task;
            }

            $this->builder->realignWindow($schedule->refresh());
            $job->refreshEstimatedHours();
        });

        $count = count($data['tasks']);

        $job->recordActivity('tasks_added', $count.' '.str('task')->plural($count).' added to the schedule');

        return $this->created([
            'jobId' => $job->id,
            'taskIds' => collect($createdTasks)->pluck('id')->all(),
        ], $count.' '.str('task')->plural($count)." added to \"{$job->name}\".");
    }

    /** @return array<string, mixed> */
    private function staffing(Job $job): array
    {
        $team = $job->team;

        return [
            'foremen' => ($team === null
                ? Foreman::where('role', Foreman::ROLE_JOURNEYMAN)->orderBy('name')->get(['id', 'name', 'initials', 'role'])
                : $team->journeymen()->get(['id', 'name', 'initials', 'role']))
                ->map(fn (Foreman $f) => ['id' => $f->id, 'name' => $f->name, 'initials' => $f->initials, 'role' => $f->role])
                ->values(),
            'supervisors' => ($team === null
                ? Foreman::where('role', Foreman::ROLE_FOREMAN)->orderBy('name')->get(['id', 'name', 'initials', 'role'])
                : $team->foremen()->get(['id', 'name', 'initials', 'role']))
                ->map(fn (Foreman $f) => ['id' => $f->id, 'name' => $f->name, 'initials' => $f->initials, 'role' => $f->role])
                ->values(),
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

    /** @param  array<int, int|null>  $ids */
    private function refuseOffCrew(Job $job, array $ids): void
    {
        $team = $job->team;
        $given = array_values(array_filter($ids));

        if ($team === null || $given === []) {
            return;
        }

        $onCrew = Foreman::whereKey($given)->where('team_id', $team->id)->pluck('id')->all();

        if (array_diff($given, $onCrew) !== []) {
            throw ValidationException::withMessages(['foreman_id' => "That person is not on {$team->name}."]);
        }
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
        abort_unless($job->user_id === $request->user()->id, 403);
    }
}
