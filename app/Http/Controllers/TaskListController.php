<?php

namespace App\Http\Controllers;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Policies\JobSchedulePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every task across every job.
 *
 * The schedule screen answers "what does this job take"; this one answers
 * "what is outstanding, and who has it" — the question you have before you know
 * which job you are looking for.
 */
class TaskListController extends Controller
{
    public function __construct(private readonly JobSchedulePolicy $policy) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', ...JobTask::STATUSES])],
            'foreman' => ['nullable', 'string', 'max:120'],
        ]);

        $status = $filters['status'] ?? 'all';
        $foreman = $filters['foreman'] ?? 'all';
        $search = trim($filters['search'] ?? '');

        /*
         * Paginated by job, not by task, because the screen is grouped by job.
         * It also lets a job with no tasks at all appear — otherwise the moment
         * you removed a job's last task it dropped off this screen entirely,
         * and there was nowhere left to add one back from.
         */
        $narrowed = $search !== '' || $status !== 'all' || $foreman !== 'all';

        $matching = fn ($query) => $query
            ->when($search !== '', fn ($inner) => $inner->where('title', 'like', "%{$search}%"))
            ->when($status !== 'all', fn ($inner) => $inner->where('status', $status))
            ->when(
                $foreman === 'unassigned',
                fn ($inner) => $inner->whereNull('foreman_id'),
                fn ($inner) => $inner->when(
                    $foreman !== 'all',
                    fn ($deeper) => $deeper->whereHas('foreman', fn ($f) => $f->where('name', $foreman)),
                )
            );

        $jobs = Job::query()
            ->active()
            // Narrowed, a job earns its place by having a task that matches;
            // unnarrowed, every job is listed so any of them can be added to.
            ->when($narrowed, fn ($query) => $query->whereHas('tasks', $matching))
            ->with([
                'tasks' => fn ($query) => $matching($query)
                    ->with('foreman:id,name,initials')
                    ->orderBy('position')
                    ->orderBy('id'),
            ])
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'client' => $job->client,
                'tasks' => $job->tasks->map(fn (JobTask $task) => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'status' => $task->status,
                    'estimatedHours' => $task->estimated_hours === null
                        ? null
                        : (float) $task->estimated_hours,
                    'foreman' => $task->foreman?->name,
                ])->values(),
            ]);

        return Inertia::render('Tasks', [
            // Wrapped, not handed over raw: a bare paginator serialises flat
            // (`current_page` at the top level), while every list screen reads
            // `meta.current_page` like the resource-backed ones give it.
            'jobs' => JsonResource::collection($jobs),
            'filters' => ['search' => $search, 'status' => $status, 'foreman' => $foreman],
            'statuses' => JobTask::STATUSES,
            // Both the filter and the edit form pick from the same register.
            'foremen' => Foreman::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Foreman $man) => ['id' => $man->id, 'name' => $man->name]),
            'canEdit' => $this->canEdit($request),
        ]);
    }

    /** Whoever plans the work may edit it; everyone else still reads the list. */
    private function canEdit(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $this->policy->updateTask($user, new JobTask);
    }

    /**
     * Adding a task starts by naming the job it is for.
     *
     * A task cannot exist on its own, and the work it covers comes from that
     * job's estimate — so this picks the job and hands over to the step that
     * already knows how to lay one out.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('TaskCreate', [
            'jobs' => Job::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'client'])
                ->map(fn (Job $job) => [
                    'id' => $job->id,
                    'name' => $job->name,
                    'client' => $job->client,
                ]),
        ]);
    }
}
