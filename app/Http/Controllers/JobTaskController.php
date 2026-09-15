<?php

namespace App\Http\Controllers;

use App\Events\ScheduleChanged;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\JobTaskAttachment;
use App\Models\JobTaskDependency;
use App\Models\TeamMember;
use App\Notifications\TaskScheduleChanged;
use App\Policies\JobSchedulePolicy;
use App\Services\Scheduling\JobTaskWorkflowService;
use App\Services\Scheduling\ScheduleBuilder;
use App\Services\Scheduling\ScheduleNotifier;
use App\Services\Scheduling\ScheduleProgress;
use App\Services\Scheduling\TaskDependencyGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything that changes a task.
 *
 * Split from `JobScheduleController` because that one only reads: this is the write
 * side, and the two have almost no overlap. Every action here ends the same way —
 * recompute the schedule's cached progress, write an activity row, tell whoever is
 * on the task — so those three are helpers rather than repeated.
 */
class JobTaskController extends Controller
{
    public function __construct(
        private readonly ScheduleProgress $progress,
        private readonly ScheduleBuilder $builder,
        private readonly ScheduleNotifier $notifier,
        private readonly JobSchedulePolicy $policy,
        private readonly JobTaskWorkflowService $workflow,
    ) {}

    public function store(Request $request, Job $job): RedirectResponse
    {
        $this->authorize('view', $job);

        $schedule = $job->schedule ?? $this->builder->build($job, $request->user(), withTasks: false);
        abort_unless($this->policy->createTask($request->user(), $schedule), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', Rule::in(JobTask::STATUSES)],
            'priority' => ['nullable', Rule::in(JobTask::PRIORITIES)],
            'category' => ['nullable', Rule::in(JobTask::CATEGORIES)],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_milestone' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'ends_on.after_or_equal' => 'A task cannot end before it starts.',
        ]);

        /*
         * A schedule cannot hold the same task twice. The database enforces it too,
         * but caught here so the planner gets a field error rather than a 500.
         */
        $exists = $schedule->tasks()
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($data['title']))])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'title' => 'This schedule already has a task with that title.',
            ]);
        }

        $task = DB::transaction(function () use ($schedule, $job, $request, $data) {
            $task = $schedule->tasks()->create([
                'job_id' => $job->id,
                'created_by' => $request->user()?->id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? JobTask::STATUS_PENDING,
                'priority' => $data['priority'] ?? 'medium',
                'category' => $data['category'] ?? null,
                'estimated_hours' => $data['estimated_hours'] ?? null,
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                // Baselined at creation, so any later move reads as slippage.
                'baseline_ends_on' => $data['ends_on'] ?? null,
                'is_milestone' => (bool) ($data['is_milestone'] ?? false),
                'notes' => $data['notes'] ?? null,
                // Appended, so a new task does not jump the running order.
                'position' => (int) $schedule->tasks()->max('position') + 1,
            ]);

            $this->builder->realignWindow($schedule);

            return $task;
        });

        $this->settle($schedule, $job, 'task_created', "Task created: {$task->title}");

        return back()->with('success', "“{$task->title}” was added to the schedule.");
    }

    public function update(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->updateTask($request->user(), $task), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(JobTask::STATUSES)],
            'priority' => ['required', Rule::in(JobTask::PRIORITIES)],
            'category' => ['nullable', Rule::in(JobTask::CATEGORIES)],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'completion_pct' => ['required', 'integer', 'between:0,100'],
            'is_milestone' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'ends_on.after_or_equal' => 'A task cannot end before it starts.',
        ]);

        $duplicate = JobTask::query()
            ->where('job_schedule_id', $task->job_schedule_id)
            ->whereKeyNot($task->id)
            ->whereRaw('lower(title) = ?', [mb_strtolower(trim($data['title']))])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'title' => 'Another task on this schedule already has that title.',
            ]);
        }

        $datesMoved = $task->starts_on?->toDateString() !== ($data['starts_on'] ?? null)
            || $task->ends_on?->toDateString() !== ($data['ends_on'] ?? null);

        $wasCompleted = $task->getOriginal('status') === JobTask::STATUS_COMPLETED;

        $task->fill([
            ...$data,
            'title' => trim($data['title']),
            'is_milestone' => (bool) ($data['is_milestone'] ?? false),
            'actual_hours' => $data['actual_hours'] ?? $task->actual_hours,
        ]);

        // Completing through the form has to behave like the dedicated action.
        if ($task->status === JobTask::STATUS_COMPLETED) {
            $task->completion_pct = 100;
            $task->completed_at ??= now();
        } elseif ($task->isDirty('status')) {
            $task->completed_at = null;
        }

        $task->save();

        if ($wasCompleted && $task->status !== JobTask::STATUS_COMPLETED) {
            // Same rule as the mobile reopen path — a task leaving
            // `completed` here undoes its own foreman's sign-off just as
            // much as reopening it from the app does, without touching any
            // other foreman's already-approved portion of the same job.
            if ($task->foreman_id !== null) {
                $task->job?->clearForemanReadyForReview($task->foreman_id);
            } else {
                $task->job?->clearReadyForReview();
            }
        }

        $this->settle($task->schedule, $task->job, 'task_updated', "Task updated: {$task->title}");

        if ($datesMoved) {
            $this->notifier->taskChanged(
                $task,
                TaskScheduleChanged::RESCHEDULED,
                except: $request->user(),
            );
        }

        return back()->with('success', "“{$task->title}” was updated.");
    }

    /**
     * Marks a task complete.
     *
     * Completing one thing can unblock several others, so the whole schedule is
     * re-evaluated afterwards and anything now clear is promoted to `ready`. Without
     * that a finished predecessor leaves its successors sitting at `pending` with
     * nothing actually stopping them.
     */
    public function complete(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->completeTask($request->user(), $task), 403);

        $data = $request->validate([
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        ['unblocked' => $unblocked] = $this->workflow->complete($task, $request->user(), $data);

        return back()->with(
            'success',
            "“{$task->title}” is complete."
                .($unblocked > 0 ? " {$unblocked} ".str('task')->plural($unblocked).' can now start.' : '')
        );
    }

    /**
     * Records a delay, with a reason and a new end date.
     *
     * The reason is required. A delay with no explanation tells the next person
     * nothing, and this row is what the delays panel and the client report both read.
     */
    public function delay(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->delayTask($request->user(), $task), 403);

        $data = $request->validate([
            'ends_on' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'reason.required' => 'Say why it slipped — the delays panel shows this.',
        ]);

        $wasDue = $task->ends_on?->format('m/d/Y') ?? 'unscheduled';
        $newDue = Carbon::parse($data['ends_on']);

        // Baselined on the first delay only, so slippage is measured from the
        // original commitment rather than from the last excuse.
        $task->baseline_ends_on ??= $task->ends_on;
        $task->ends_on = $newDue->toDateString();
        $task->status = JobTask::STATUS_DELAYED;
        $task->notes = $data['reason'];

        if ($task->starts_on !== null && $task->starts_on->gt($newDue)) {
            // Moving the end before the start would leave the row self-contradictory.
            $task->starts_on = $newDue->toDateString();
        }

        $task->save();

        $this->settle(
            $task->schedule,
            $task->job,
            'task_delayed',
            "Task delayed: {$task->title} — {$wasDue} → {$newDue->format('m/d/Y')}. {$data['reason']}",
        );

        $this->notifier->taskChanged(
            $task,
            TaskScheduleChanged::DELAYED,
            "Moved from {$wasDue} to {$newDue->format('m/d/Y')}. {$data['reason']}",
            except: $request->user(),
        );

        return back()->with('warning', "“{$task->title}” was marked delayed.");
    }

    /**
     * Moves a task's dates — the drag-and-drop reschedule.
     *
     * The span is preserved: dragging a five-day task moves all five days rather than
     * turning it into a one-day task on the new date. Dependency breaks are reported
     * back rather than refused, because the dates may be the correction.
     */
    public function move(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->updateTask($request->user(), $task), 403);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ], [
            'ends_on.after_or_equal' => 'A task cannot end before it starts.',
        ]);

        $newStart = Carbon::parse($data['starts_on'])->startOfDay();

        if (isset($data['ends_on'])) {
            $newEnd = Carbon::parse($data['ends_on'])->startOfDay();
        } else {
            // Keep the duration the task already had.
            $span = $task->starts_on && $task->ends_on
                ? (int) $task->starts_on->diffInDays($task->ends_on)
                : 0;
            $newEnd = $newStart->copy()->addDays($span);
        }

        $wasStart = $task->starts_on?->format('m/d') ?? 'unscheduled';

        $task->baseline_ends_on ??= $task->ends_on;
        $task->forceFill([
            'starts_on' => $newStart->toDateString(),
            'ends_on' => $newEnd->toDateString(),
        ])->save();

        $schedule = $task->schedule;
        $this->builder->realignWindow($schedule);

        $breaches = TaskDependencyGraph::for($schedule)->breaches();
        $mine = array_values(array_filter(
            $breaches,
            fn (array $breach) => $breach['taskId'] === $task->id || $breach['dependsOnId'] === $task->id,
        ));

        $this->settle(
            $schedule,
            $task->job,
            'task_moved',
            "Task moved: {$task->title} — {$wasStart} → {$newStart->format('m/d')}",
        );

        $this->notifier->taskChanged($task, TaskScheduleChanged::RESCHEDULED, except: $request->user());

        // A warning rather than a refusal: the planner may be mid-way through fixing
        // a sequence, and blocking the save would leave neither end correctable.
        return $mine === []
            ? back()->with('success', "“{$task->title}” moved to {$newStart->format('m/d/Y')}.")
            : back()->with('warning', "“{$task->title}” moved, but ".count($mine).' dependency '
                .str('constraint')->plural(count($mine)).' no longer '
                .(count($mine) === 1 ? 'holds' : 'hold').': '.$mine[0]['problem'].'.');
    }

    /**
     * Rewrites the running order — the drag-and-drop reorder.
     *
     * The whole order arrives at once rather than one moved id, so the result cannot
     * depend on which end the client counted from.
     */
    public function reorder(Request $request, Job $job): RedirectResponse
    {
        $schedule = $job->schedule;
        abort_unless($schedule !== null, 404);
        abort_unless($this->policy->reorder($request->user(), $schedule), 403);

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $owned = $schedule->tasks()->pluck('id')->all();
        $order = array_values(array_unique(array_map('intval', $data['order'])));

        // Every id must belong to this schedule, or a drag could reorder another job.
        if (array_diff($order, $owned) !== []) {
            throw ValidationException::withMessages([
                'order' => 'That order refers to tasks which are not on this schedule.',
            ]);
        }

        DB::transaction(function () use ($order) {
            foreach ($order as $position => $id) {
                JobTask::whereKey($id)->update(['position' => $position]);
            }
        });

        $job->recordActivity('task_reordered', 'Task order changed', ['order' => $order]);

        return back()->with('success', 'The running order was saved.');
    }

    public function destroy(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->deleteTask($request->user(), $task), 403);

        $title = $task->title;
        $schedule = $task->schedule;
        $job = $task->job;

        // Dependencies cascade, so removing a task cannot leave a dangling edge.
        $task->delete();

        // The job is the sum of its tasks, so one fewer changes the total.
        $job?->refreshEstimatedHours();

        $this->settle($schedule, $job, 'task_deleted', "Task removed: {$title}");

        return back()->with('warning', "“{$title}” was removed from the schedule.");
    }

    /* --------------------------------------------------------------- staffing */

    public function assign(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->assign($request->user(), $task), 403);

        $data = $request->validate([
            'team_member_id' => ['required', 'integer', 'exists:team_members,id'],
            'role' => ['required', Rule::in(JobTask::ROLES)],
        ]);

        $already = $task->assignments()
            ->where('team_member_id', $data['team_member_id'])
            ->where('role', $data['role'])
            ->exists();

        if ($already) {
            throw ValidationException::withMessages([
                'team_member_id' => 'That person already holds this role on the task.',
            ]);
        }

        $task->assignments()->create([
            'team_member_id' => $data['team_member_id'],
            'assigned_by' => $request->user()?->id,
            'role' => $data['role'],
        ]);

        $member = TeamMember::find($data['team_member_id']);

        $this->settle(
            $task->schedule,
            $task->job,
            'task_assigned',
            "{$member?->name} assigned to {$task->title} as {$data['role']}",
        );

        // Reloaded so the notifier sees the assignment it is about to announce.
        $this->notifier->taskChanged(
            $task->fresh(['assignments.member', 'job']),
            TaskScheduleChanged::ASSIGNED,
            except: $request->user(),
        );

        return back()->with('success', "{$member?->name} was assigned to “{$task->title}”.");
    }

    public function unassign(Request $request, JobTask $task, int $assignment): RedirectResponse
    {
        abort_unless($this->policy->assign($request->user(), $task), 403);

        $row = $task->assignments()->with('member')->findOrFail($assignment);
        $name = $row->member?->name ?? 'Someone';
        $row->delete();

        $this->settle(
            $task->schedule,
            $task->job,
            'task_unassigned',
            "{$name} removed from {$task->title}",
        );

        return back()->with('warning', "{$name} was removed from “{$task->title}”.");
    }

    /* ----------------------------------------------------------- dependencies */

    /**
     * Adds a dependency, refusing anything that would make the schedule impossible.
     *
     * A cycle is the one thing that cannot be allowed through: a schedule where A
     * waits on B and B waits on A can never start, and no amount of date-fixing
     * repairs it. Broken *dates* are a warning; a broken *graph* is an error.
     */
    public function addDependency(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->updateTask($request->user(), $task), 403);

        $data = $request->validate([
            'depends_on_id' => ['required', 'integer', 'exists:job_tasks,id'],
            'type' => ['required', Rule::in(JobTaskDependency::TYPES)],
            'lag_days' => ['nullable', 'integer', 'between:-60,60'],
        ]);

        $predecessor = JobTask::findOrFail($data['depends_on_id']);

        if ($predecessor->job_schedule_id !== $task->job_schedule_id) {
            throw ValidationException::withMessages([
                'depends_on_id' => 'A task can only depend on another task on the same schedule.',
            ]);
        }

        if ($predecessor->id === $task->id) {
            throw ValidationException::withMessages([
                'depends_on_id' => 'A task cannot depend on itself.',
            ]);
        }

        if ($task->dependencies()->where('depends_on_id', $predecessor->id)->exists()) {
            throw ValidationException::withMessages([
                'depends_on_id' => 'That dependency is already recorded.',
            ]);
        }

        if (TaskDependencyGraph::for($task->schedule)->wouldCycle($task->id, $predecessor->id)) {
            throw ValidationException::withMessages([
                'depends_on_id' => "That would create a circular dependency — “{$predecessor->title}” already waits on this task, directly or through others.",
            ]);
        }

        $task->dependencies()->create([
            'depends_on_id' => $predecessor->id,
            'type' => $data['type'],
            'lag_days' => $data['lag_days'] ?? 0,
        ]);

        $this->settle(
            $task->schedule,
            $task->job,
            'dependency_added',
            "{$task->title} now waits on {$predecessor->title}",
        );

        return back()->with('success', "“{$task->title}” now waits on “{$predecessor->title}”.");
    }

    public function removeDependency(Request $request, JobTask $task, int $dependency): RedirectResponse
    {
        abort_unless($this->policy->updateTask($request->user(), $task), 403);

        $edge = $task->dependencies()->with('dependsOn')->findOrFail($dependency);
        $title = $edge->dependsOn?->title ?? 'another task';
        $edge->delete();

        $this->settle(
            $task->schedule,
            $task->job,
            'dependency_removed',
            "{$task->title} no longer waits on {$title}",
        );

        return back()->with('success', "“{$task->title}” no longer waits on “{$title}”.");
    }

    /* --------------------------------------------------------------- comments */

    public function comment(Request $request, JobTask $task): RedirectResponse
    {
        abort_unless($this->policy->comment($request->user(), $task), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        $task->comments()->create([
            'user_id' => $request->user()?->id,
            'body' => trim($data['body']),
        ]);

        return back()->with('success', 'Comment added.');
    }

    /**
     * A field photo's bytes — session-authenticated (this is the web app, no
     * `Bearer` token to check), the same `inline` streaming shape the
     * mobile-only route already uses (`Api\V1\JobTaskAttachmentController
     * ::show()`) so an `<img>` tag on the job detail screen can load it
     * directly.
     */
    public function attachment(JobTask $task, JobTaskAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $task->job);
        abort_unless($attachment->job_task_id === $task->id, 404);

        return response()->streamDownload(
            fn () => print(Storage::disk('local')->get($attachment->path)),
            $attachment->name,
            ['Content-Type' => $attachment->mime_type ?? 'application/octet-stream'],
            'inline',
        );
    }

    /* ------------------------------------------------------------- internals */

    /**
     * The three things every write does afterwards.
     *
     * Progress is a cache with one writer; the activity row is the audit trail; the
     * ready sweep is what keeps `pending` from meaning two different things. Kept
     * together so no action can forget one of them.
     */
    private function settle(?JobSchedule $schedule, ?Job $job, string $type, string $description): void
    {
        if ($schedule !== null) {
            $this->builder->markReady($schedule);
            $this->progress->refresh($schedule);
        }

        $job?->recordActivity($type, $description);

        if ($schedule !== null && $job !== null) {
            event(new ScheduleChanged($job->id, $type, $description));
        }
    }
}
