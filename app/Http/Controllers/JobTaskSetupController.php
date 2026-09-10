<?php

namespace App\Http\Controllers;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\User;
use App\Notifications\TaskScheduleChanged;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\ScheduleBuilder;
use App\Services\Takeoff\TakeoffFlow;
use App\Support\JobOrigin;
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
 * A job needs at least one task, and a task needs a name, a foreman and the
 * lines it covers. Planning is part of raising a job here, not an optional
 * extra — the step before this one has already created the job.
 */
class JobTaskSetupController extends Controller
{
    public function __construct(
        private readonly ScheduleBuilder $builder,
        private readonly JobSchedulePolicy $policy,
        private readonly TakeoffFlow $flow,
    ) {}

    public function create(Request $request, Job $job): Response
    {
        $this->authorisePlanning($job, $request->user());

        // The last step, and still a step: remembered so leaving it mid-way
        // leaves a way back.
        if ($job->project !== null) {
            $this->flow->remember($job->project);
        }

        $job->loadMissing(['schedule.tasks.foreman', 'schedule.tasks.estimateItems']);

        return Inertia::render('JobTaskSetup', [
            'returnUrl' => $this->returnUrl($request, $job),
            // Carries the origin through the save, so finishing lands back
            // where the planner started rather than always in the flow.
            'saveUrl' => $this->carryOrigin($request, route('jobs.tasks.setup.store', $job)),
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'location' => $job->location,
                'startDate' => $job->start_date?->toDateString(),
                'endDate' => $job->end_date?->toDateString(),
                /*
                 * Only a job raised from a takeoff has an analysis, a review and
                 * an estimate behind it. A job created by hand has none of that,
                 * and drawing the takeoff roadmap over it would be a lie.
                 */
                'fromTakeoff' => $job->ai_result_id !== null,
                /** The review summary this job was raised from — the step before. */
                'takeoffUrl' => $job->ai_result_id === null
                    ? null
                    : route('finals.show', $job->ai_result_id),
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
                'foreman' => $task->foreman?->name,
                'supervisor' => $task->supervisor?->name,
                'lineCount' => $task->estimateItems->count(),
            ])->values() ?? [],
            ...$this->staffing($job),
        ]);
    }

    /**
     * Who a task on this job can be given to.
     *
     * Narrowed to the job's own crew: the whole point of handing a job to a
     * team is that the work on it goes to that team. A job with no crew falls
     * back to the whole register — otherwise a job raised before teams existed
     * could not be staffed at all, which would be a worse answer than a long
     * list.
     *
     * @return array<string, mixed>
     */
    private function staffing(Job $job): array
    {
        $team = $job->team;

        return [
            'foremen' => $team === null
                ? Foreman::orderBy('name')->get(['id', 'name', 'initials'])
                : $team->foremen()->get(['id', 'name', 'initials']),
            'supervisors' => $team === null
                ? Foreman::where('role', Foreman::ROLE_SUPERVISOR)
                    ->orderBy('name')->get(['id', 'name', 'initials'])
                : $team->supervisors()->get(['id', 'name', 'initials']),
            /*
             * Said on the screen, because "why is this list so short" is the
             * first question a narrowed picker raises.
             */
            'team' => $team === null ? null : ['id' => $team->id, 'name' => $team->name],
        ];
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

        /*
         * Lines are required — except on a job that has no estimate at all,
         * where there is nothing to require. Demanding them there would leave
         * the screen impossible to complete rather than merely strict.
         */
        $hasLines = $this->estimateLineCount($job) > 0;

        $data = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:50'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.foreman_id' => ['required', 'integer', 'exists:foremen,id'],
            /*
             * Who is over the task. Optional: plenty of work needs somebody
             * running it and nobody above them.
             */
            'tasks.*.supervisor_id' => ['nullable', 'integer', 'exists:foremen,id'],
            /*
             * No `distinct`: with a nested wildcard it compares across every
             * task, not within one, and would report the right refusal under an
             * unreadable key. Checked below, where the message can say what
             * actually happened.
             */
            'tasks.*.estimate_item_ids' => $hasLines
                ? ['required', 'array', 'min:1', 'max:200']
                : ['nullable', 'array', 'max:200'],
            'tasks.*.estimate_item_ids.*' => ['integer'],
        ], [
            'tasks.required' => 'A job needs at least one task.',
            'tasks.*.title.required' => 'Give the task a name, or remove the row.',
            'tasks.*.foreman_id.required' => 'Pick the foreman running this task.',
            'tasks.*.estimate_item_ids.required' => 'Pick the estimate lines this task covers.',
            'tasks.*.estimate_item_ids.min' => 'Pick the estimate lines this task covers.',
        ]);

        // Everyone named across every row, against the job's own crew.
        $this->refuseOffCrew($job, array_merge(
            array_column($data['tasks'], 'foreman_id'),
            array_column($data['tasks'], 'supervisor_id'),
        ));

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

        // Filled inside the transaction, notified after it commits — a
        // notification sent for a task whose transaction then rolled back
        // would tell someone they're on work that was never actually saved.
        $createdTasks = [];

        DB::transaction(function () use ($schedule, $job, $request, $data, &$createdTasks) {
            // Appended after whatever is already planned, so re-running the step
            // extends the schedule instead of renumbering it.
            $position = (int) $schedule->tasks()->max('position');

            foreach ($data['tasks'] as $row) {
                $position++;

                $laborLineIds = $row['estimate_item_ids'] ?? [];
                // Every material a picked labor line installs rides along
                // with it automatically — the planner never picks it
                // separately.
                $lineIds = array_values(array_unique(array_merge(
                    $laborLineIds,
                    $this->pairedMaterialLines($laborLineIds),
                )));

                $task = $schedule->tasks()->create([
                    'job_id' => $job->id,
                    'created_by' => $request->user()?->id,
                    'title' => trim($row['title']),
                    'foreman_id' => $row['foreman_id'] ?? null,
                    'supervisor_id' => $row['supervisor_id'] ?? null,
                    /*
                     * Read off the lines rather than typed: the estimate already
                     * priced this work in hours, and asking for the number again
                     * only invites the two to disagree.
                     */
                    'estimated_hours' => $this->hoursOn($lineIds),
                    // Category and dates belong to the schedule screen, where the
                    // plan is worked rather than started.
                    'priority' => 'medium',
                    'status' => JobTask::STATUS_PENDING,
                    'position' => $position,
                ]);

                // Claiming the lines is what takes them out of the picker.
                if ($lineIds !== []) {
                    EstimateItem::whereIn('id', $lineIds)->update(['job_task_id' => $task->id]);
                }

                $createdTasks[] = $task;
            }

            $this->builder->realignWindow($schedule->refresh());

            $job->refreshEstimatedHours();
        });

        // A freshly-created task's foreman/supervisor is always a new
        // assignment — nobody was on it a moment ago.
        foreach ($createdTasks as $task) {
            $this->notifyAssignment($task);
        }

        // The takeoff has become a job with its work laid out. Nothing left to
        // resume, so the floating button goes.
        $this->flow->forget();

        $count = count($data['tasks']);

        $job->recordActivity(
            'tasks_added',
            $count.' '.str('task')->plural($count).' added to the schedule',
        );

        /*
         * The end of the flow. The takeoff has become a job with its work laid
         * out, so this lands on the jobs list rather than pushing on into
         * scheduling — that is its own module, worked over days. Opened from
         * the task list to add work to a job already running, it goes back
         * there instead: that is where the planner was.
         */
        return redirect()
            ->to($this->returnUrl($request, $job) ?? route('jobs.index'))
            ->with('success', $count.' '.str('task')->plural($count).' added to “'.$job->name.'”.');
    }

    /**
     * One task on the same screen that created it.
     *
     * Deliberately the same shape as the setup step rather than a smaller form:
     * a task is its name, its foreman and the estimate lines it covers, and
     * changing which lines it covers is the whole reason to open it. A narrower
     * editor would leave the plan and the estimate free to drift apart.
     */
    public function edit(Request $request, JobTask $task): Response
    {
        $job = $this->jobBehind($task);

        $this->authorisePlanning($job, $request->user());

        $task->loadMissing('estimateItems:id,job_task_id');

        return Inertia::render('JobTaskEdit', [
            'returnUrl' => $this->returnUrl($request, $job) ?? route('tasks.index'),
            'saveUrl' => $this->carryOrigin($request, route('tasks.edit.update', $task)),
            'deleteUrl' => $this->carryOrigin($request, route('tasks.remove', $task)),
            'job' => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
            ],
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'foremanId' => $task->foreman_id,
                'supervisorId' => $task->supervisor_id,
                'lineIds' => $task->estimateItems->pluck('id')->values(),
            ],
            /*
             * This task's own lines arrive unclaimed. They are claimed — by
             * this task — but the picker greys out anything with a task on it,
             * so leaving them marked would make a line impossible to put back
             * the moment it was unticked.
             */
            'estimateLines' => $this->lines($job, $task),
            ...$this->staffing($job),
            'statuses' => JobTask::STATUSES,
        ]);
    }

    /**
     * Saves the task, and keeps the estimate in step with it.
     *
     * Which lines a task covers is the one thing here with consequences beyond
     * the row: a dropped line goes back into the picker for another task, a
     * newly taken one comes out of it, the task's hours are re-read off the
     * labour it now covers, and the job's total is recomputed from its tasks.
     * All in one transaction, because a plan that half-agrees with its estimate
     * is worse than one that disagrees outright.
     */
    /**
     * Refuses anyone who is not on the job's crew.
     *
     * The pickers only offer the crew, so this catches a hand-made request
     * rather than a mistake at the screen — but the rule has to live on the
     * server or the narrowing is decoration. A job with no crew is not
     * narrowed: it has nobody to be off.
     *
     * @param  array<int, int|null>  $ids
     */
    private function refuseOffCrew(Job $job, array $ids): void
    {
        $team = $job->team;
        $given = array_values(array_filter($ids));

        if ($team === null || $given === []) {
            return;
        }

        $onCrew = Foreman::whereKey($given)->where('team_id', $team->id)->pluck('id')->all();

        if (array_diff($given, $onCrew) !== []) {
            throw ValidationException::withMessages([
                'foreman_id' => "That person is not on {$team->name}.",
            ]);
        }
    }

    public function update(Request $request, JobTask $task): RedirectResponse
    {
        $job = $this->jobBehind($task);

        $this->authorisePlanning($job, $request->user());

        $hasLines = $this->estimateLineCount($job) > 0;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'status' => ['required', Rule::in(JobTask::STATUSES)],
            'foreman_id' => ['required', 'integer', 'exists:foremen,id'],
            'supervisor_id' => ['nullable', 'integer', 'exists:foremen,id'],
            'estimate_item_ids' => $hasLines
                ? ['required', 'array', 'min:1', 'max:200']
                : ['nullable', 'array', 'max:200'],
            'estimate_item_ids.*' => ['integer'],
        ], [
            'title.required' => 'Give the task a name.',
            'foreman_id.required' => 'Pick the foreman running this task.',
            'estimate_item_ids.required' => 'Pick the estimate lines this task covers.',
            'estimate_item_ids.min' => 'Pick the estimate lines this task covers.',
        ]);

        $this->refuseOffCrew($job, [$data['foreman_id'], $data['supervisor_id'] ?? null]);

        $title = trim($data['title']);

        $clash = JobTask::query()
            ->where('job_id', $job->id)
            ->whereKeyNot($task->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'title' => 'This job already has a task with that name.',
            ]);
        }

        $laborIds = array_values(array_unique(array_map(
            intval(...),
            $data['estimate_item_ids'] ?? [],
        )));

        // Free, or already this task's own — anything else belongs elsewhere.
        // Labor only: the picker never submits a material line directly, its
        // material rides along automatically (see `pairedMaterialLines()`).
        $allowed = EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->where(fn ($query) => $query
                ->whereNull('job_task_id')
                ->orWhere('job_task_id', $task->id))
            ->pluck('id')
            ->all();

        if (array_diff($laborIds, $allowed) !== []) {
            throw ValidationException::withMessages([
                'estimate_item_ids' => 'One of those lines is already planned into another task. Reload the page.',
            ]);
        }

        // Every material a picked labor line installs rides along with it
        // automatically. Matched against this task's own already-claimed
        // lines too, so re-saving with no changes does not detach material
        // it already correctly holds.
        $ids = array_values(array_unique(array_merge(
            $laborIds,
            $this->pairedMaterialLines($laborIds, $task->id),
        )));

        DB::transaction(function () use ($task, $job, $title, $data, $ids) {
            $task->update([
                'title' => $title,
                'status' => $data['status'],
                'foreman_id' => $data['foreman_id'],
                'supervisor_id' => $data['supervisor_id'] ?? null,
                // Re-read off the labour it now covers, never typed.
                'estimated_hours' => $this->hoursOn($ids),
            ]);

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

        // Only when the assignment itself actually changed — re-saving a
        // task's lines or title with the same foreman/supervisor is not a
        // new assignment, and would otherwise re-notify them every edit.
        if ($task->wasChanged('foreman_id') || $task->wasChanged('supervisor_id')) {
            $this->notifyAssignment($task);
        }

        return redirect()
            ->to($this->returnUrl($request, $job) ?? route('tasks.index'))
            ->with('success', 'Task updated.');
    }

    /**
     * Tells whoever is newly running or overseeing a task — reused for both
     * a task's first breakout ({@see store()}, always a new assignment) and
     * a later reassignment ({@see update()}, only called there when the
     * foreman/supervisor actually changed).
     */
    private function notifyAssignment(JobTask $task): void
    {
        $task->loadMissing('foreman.user', 'supervisor.user');

        if ($task->foreman?->user !== null) {
            $task->foreman->user->notify(new TaskScheduleChanged($task, TaskScheduleChanged::ASSIGNED));
        }

        if ($task->supervisor?->user !== null
            && $task->supervisor->user->isNot($task->foreman?->user)) {
            $task->supervisor->user->notify(new TaskScheduleChanged($task, TaskScheduleChanged::ASSIGNED));
        }
    }

    /**
     * The job a task belongs to, or a 404.
     *
     * A task outlives its job's delete — the delete is soft, with an Undo — so
     * this relation really can come back null, and everything below reads the
     * job. Left unguarded it was a type error rather than a missing page.
     */
    private function jobBehind(JobTask $task): Job
    {
        return $task->job ?? abort(404);
    }

    /**
     * Removing a task.
     *
     * The lines it covered go back into the picker for another task to take,
     * and the job's hours are re-read off what is left. A task deleted without
     * that would leave its share of the estimate planned into nothing and the
     * job still billing for hours nobody is working.
     */
    public function destroy(Request $request, JobTask $task): RedirectResponse
    {
        $job = $this->jobBehind($task);

        $this->authorisePlanning($job, $request->user());

        $title = $task->title;

        DB::transaction(function () use ($task, $job) {
            EstimateItem::where('job_task_id', $task->id)->update(['job_task_id' => null]);

            // Dependencies cascade, so this cannot leave a dangling edge.
            $task->delete();

            $job->refreshEstimatedHours();
        });

        $job->recordActivity('task_deleted', "Task removed: {$title}");

        return redirect()
            ->to($this->returnUrl($request, $job) ?? route('tasks.index'))
            ->with('warning', "“{$title}” was removed from “{$job->name}”.");
    }

    /**
     * Where the planner was before they opened this screen.
     *
     * A whitelisted marker rather than a URL off the query string: the value
     * decides where a redirect lands, and a redirect that will follow anything
     * handed to it is an open redirect.
     */
    private function returnUrl(Request $request, Job $job): ?string
    {
        return match ($request->query('from')) {
            'tasks' => route('tasks.index'),
            /*
             * Back to the job — carrying whatever trail the job itself was
             * opened on. Without `origin`, coming out of a task landed on a job
             * that had forgotten it came from Scheduling, and the next Back
             * dropped the planner in the jobs list instead.
             */
            'job' => JobOrigin::jobUrl($job->id, $request->query('origin')),
            default => null,
        };
    }

    /**
     * The same marker on the URL the form submits to.
     *
     * Without it the origin is lost the moment the screen posts, and a planner
     * who came from a job's own page would be dropped somewhere else on save.
     */
    private function carryOrigin(Request $request, string $url): string
    {
        $from = $request->query('from');

        if (! in_array($from, ['tasks', 'job'], true)) {
            return $url;
        }

        // The job's own trail rides along, so a save walks the same path out
        // that the planner walked in.
        $origin = JobOrigin::name($request->query('origin'));

        return $url.'?from='.$from.($origin === null ? '' : '&origin='.$origin);
    }

    /**
     * The estimates whose lines are this job's work to plan.
     *
     * Its own estimates, plus the one raised from the takeoff it was built
     * from. Those are not always the same row: the takeoff's estimate is
     * created when the review is signed off, before any job exists, and it is
     * linked to whichever job was raised first. A second job off the same
     * takeoff would otherwise arrive here with nothing to plan from.
     *
     * @return Collection<int, int>
     */
    private function estimateIds(Job $job): Collection
    {
        $own = $job->estimates()->pluck('id');

        if ($job->ai_result_id === null) {
            return $own;
        }

        return $own
            ->merge(Estimate::where('ai_result_id', $job->ai_result_id)->pluck('id'))
            ->unique()
            ->values();
    }

    /**
     * Every estimate line on this job, with what has already claimed it.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(Job $job, ?JobTask $editing = null): array
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            // Only labor lines are ever picked directly — a material line has
            // no work of its own to schedule. Its material rides along with
            // whichever labor line installs it (see `pairedMaterialLines()`),
            // so it never needs its own row in this picker.
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
                // Null while the line is still free to plan — and a line on the
                // task being edited is free as far as that screen is concerned.
                'taskId' => $item->job_task_id === $editing?->id ? null : $item->job_task_id,
                'taskTitle' => $item->job_task_id === $editing?->id ? null : $item->task?->title,
            ])->all();
    }

    /**
     * The labour hours the given estimate lines add up to.
     *
     * Only labour: a task's estimated hours are hours of work, and adding a
     * material line's 50 ft of cable to them would be nonsense. Null when the
     * lines carry no labour at all, because zero would claim the work is free.
     *
     * @param  list<int>  $lineIds
     */
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

    /** How many estimate lines this job has at all, claimed or not. */
    private function estimateLineCount(Job $job): int
    {
        return EstimateItem::whereIn('estimate_id', $this->estimateIds($job))->count();
    }

    /**
     * The labor lines this job's estimates hold that nothing has claimed yet
     * — the only lines ever picked directly. A material line is never
     * claimable on its own; it only ever comes along with the labor line
     * that pairs with it (see `pairedMaterialLines()`).
     *
     * @return Collection<int, int>
     */
    private function claimableLines(Job $job): Collection
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', $this->estimateIds($job))
            ->where('category', EstimateItem::CATEGORY_LABOR)
            ->whereNull('job_task_id')
            ->pluck('id');
    }

    /**
     * The material/fixture/equipment lines that belong with the given labor
     * lines — matched by `final_symbol_id`, the same takeoff-device link
     * `EstimateBuilder` stamps on a labor line and its material when both
     * are written for one symbol (e.g. "CEILING MOUNTED DUPLEX RECEPTACLE"
     * as labor, and the receptacle itself as material, share one symbol).
     * A manually-added labor line has no symbol and so pairs with nothing —
     * there is no estimate data to match it against.
     *
     * Scoped to lines still free to claim, or already claimed by
     * [$editingTaskId] itself — re-saving a task must not detach material it
     * already correctly holds just because it wasn't resubmitted (the
     * picker never lets material be submitted directly).
     *
     * @param  list<int>  $laborLineIds
     * @return list<int>
     */
    private function pairedMaterialLines(array $laborLineIds, ?int $editingTaskId = null): array
    {
        if ($laborLineIds === []) {
            return [];
        }

        $symbolIds = EstimateItem::whereIn('id', $laborLineIds)
            ->whereNotNull('final_symbol_id')
            ->pluck('final_symbol_id')
            ->unique()
            ->all();

        if ($symbolIds === []) {
            return [];
        }

        return EstimateItem::query()
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
