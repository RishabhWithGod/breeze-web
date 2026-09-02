<?php

namespace App\Http\Controllers;

use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\User;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Breaking a job into the work it takes, straight after it is created.
 *
 * A task is built from the job's own estimate lines rather than typed from
 * nothing. That is the point of the screen: the estimate already says what the
 * work is and what it is worth, so planning it should be a matter of grouping
 * those lines, not retyping them and hoping the two lists agree.
 *
 * A line belongs to one task. Once it is in one it is out of the picker, so the
 * same priced work cannot be scheduled twice — deleting the task puts it back.
 *
 * Skipping is a real choice: a job can be planned later, or never.
 */
class JobTaskSetupController extends Controller
{
    public function __construct(
        private readonly ScheduleBuilder $builder,
        private readonly JobSchedulePolicy $policy,
    ) {}

    public function create(Request $request, Job $job): Response
    {
        $this->authorisePlanning($job, $request->user());

        $job->loadMissing(['schedule.tasks.foremen', 'schedule.tasks.estimateItems']);

        return Inertia::render('JobTaskSetup', [
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
            ],
            /*
             * Every line on the job's estimates, each saying whether it is
             * already planned and into what. Sent whole rather than only the
             * free ones, so the picker can show the taken ones greyed with the
             * task that has them — "why can't I pick this" is answered on the
             * spot instead of the row simply being absent.
             */
            'estimateLines' => $this->lines($job),
            'existingTasks' => $job->schedule?->tasks->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'category' => $task->category,
                'estimatedHours' => $task->estimated_hours === null
                    ? null
                    : (float) $task->estimated_hours,
                'foremen' => $task->foremen->map(fn (Foreman $foreman) => [
                    'id' => $foreman->id,
                    'name' => $foreman->name,
                    'initials' => $foreman->initials,
                ])->values(),
                'lineCount' => $task->estimateItems->count(),
            ])->values() ?? [],
            'foremen' => Foreman::orderBy('name')->get(['id', 'name', 'initials']),
            'categories' => JobTask::CATEGORIES,
            'priorities' => JobTask::PRIORITIES,
        ]);
    }

    /**
     * Records the whole list in one write.
     *
     * All or nothing: a half-saved plan is worse than none, because the person
     * who typed it cannot tell which rows landed.
     */
    public function store(Request $request, Job $job): RedirectResponse
    {
        $this->authorisePlanning($job, $request->user());

        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.category' => ['nullable', Rule::in(JobTask::CATEGORIES)],
            'tasks.*.priority' => ['nullable', Rule::in(JobTask::PRIORITIES)],
            'tasks.*.estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'tasks.*.starts_on' => ['nullable', 'date'],
            'tasks.*.ends_on' => ['nullable', 'date', 'after_or_equal:tasks.*.starts_on'],
            'tasks.*.foreman_ids' => ['nullable', 'array', 'max:10'],
            'tasks.*.foreman_ids.*' => ['integer', 'distinct', 'exists:foremen,id'],
            'tasks.*.estimate_item_ids' => ['nullable', 'array', 'max:200'],
            'tasks.*.estimate_item_ids.*' => ['integer', 'distinct'],
        ], [
            'tasks.required' => 'Add at least one task, or skip this step.',
            'tasks.*.title.required' => 'Give the task a name, or remove the row.',
            'tasks.*.ends_on.after_or_equal' => 'A task cannot end before it starts.',
        ]);

        /*
         * Everything is checked before anything is built. Creating the schedule
         * first would leave an empty one behind every time a name clashed — a
         * write the request went on to reject.
         */
        $this->rejectDuplicateTitles(
            $job->schedule?->tasks()->pluck('title') ?? collect(),
            $data['tasks'],
        );

        $claimable = $this->claimableLines($job);
        $this->rejectUnavailableLines($claimable, $data['tasks']);

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user(), withTasks: false);

        DB::transaction(function () use ($schedule, $job, $request, $data) {
            // Appended after whatever is already planned, so re-running the step
            // extends the schedule instead of renumbering it.
            $position = (int) $schedule->tasks()->max('position');

            foreach ($data['tasks'] as $row) {
                $position++;

                $task = $schedule->tasks()->create([
                    'job_id' => $job->id,
                    'created_by' => $request->user()?->id,
                    'title' => trim($row['title']),
                    'category' => $row['category'] ?? null,
                    'priority' => $row['priority'] ?? 'medium',
                    'estimated_hours' => $row['estimated_hours'] ?? null,
                    'starts_on' => $row['starts_on'] ?? null,
                    'ends_on' => $row['ends_on'] ?? null,
                    'status' => JobTask::STATUS_PENDING,
                    'position' => $position,
                ]);

                $task->foremen()->sync($row['foreman_ids'] ?? []);

                // Claiming the lines is what takes them out of the picker.
                if (($row['estimate_item_ids'] ?? []) !== []) {
                    EstimateItem::whereIn('id', $row['estimate_item_ids'])
                        ->update(['job_task_id' => $task->id]);
                }
            }

            $this->builder->realignWindow($schedule->refresh());
        });

        $count = count($data['tasks']);

        $job->recordActivity(
            'tasks_added',
            $count.' '.str('task')->plural($count).' added to the schedule',
        );

        return redirect()
            ->route('jobs.show', $job)
            ->with('success', $count.' '.str('task')->plural($count).' added to “'.$job->name.'”.');
    }

    /**
     * Every estimate line on this job, with what has already claimed it.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Job $job): array
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $job->estimates()->select('id'))
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
                // Null while the line is still free to plan.
                'taskId' => $item->job_task_id,
                'taskTitle' => $item->task?->title,
            ])->all();
    }

    /**
     * The lines this job's estimates hold that nothing has claimed yet.
     *
     * @return Collection<int, int>
     */
    private function claimableLines(Job $job): Collection
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $job->estimates()->select('id'))
            ->whereNull('job_task_id')
            ->pluck('id');
    }

    /**
     * Refuses a line that is not this job's, or is already in another task.
     *
     * Checked rather than trusted: the picker greys those rows out, but a stale
     * screen or a hand-made request would otherwise move work between tasks
     * silently, and the estimate would stop matching the plan.
     *
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
                    $errors["tasks.{$index}.estimate_item_ids"] =
                        'One of those lines is already planned into another task. Reload the page.';

                    break;
                }

                // Claimed within this same submission: two rows cannot share it.
                unset($free[$at]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Planning a job is a planning decision — the same one `JobTaskController`
     * gates on. Checked against the job's schedule when it has one, and against
     * the one it is about to get when it does not: the policy asks about the
     * user's role, not about that particular schedule.
     */
    private function authorisePlanning(Job $job, User $user): void
    {
        abort_unless(
            $this->policy->createTask($user, $job->schedule ?? new JobSchedule),
            403,
        );
    }

    /**
     * A schedule cannot hold the same task twice — the database says so too.
     * Caught here so the planner gets the row back with a message rather than
     * a 500 halfway through their list.
     *
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
}
