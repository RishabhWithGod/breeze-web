<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Foreman;
use App\Models\JobApprenticeAssignment;
use App\Models\JobForemanCompletion;
use App\Models\JobTask;
use App\Models\User;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My Tasks" — every task on every job this user can access
 * (`ElectricianJobAccess::assignedJobsQuery`), flattened across jobs. No
 * assignee filter: the existing per-job endpoint
 * (`JobTaskController::index`) already returns every task on the one job
 * with no "assigned to me" narrowing, so this mirrors that exactly rather
 * than inventing a new filtering rule Breeze Web has no equivalent for —
 * there is no cross-job "My Tasks" page on web to match against
 * (`TaskListController`, web, is a company-wide planner view grouped by
 * job, not a per-user feed).
 *
 * Newest first (`created_at desc`), not `position` — `position` is a
 * manual per-job schedule order that isn't comparable once tasks from
 * different jobs are interleaved.
 */
class TaskController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly ElectricianJobAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $myForemanId = $user->foreman?->id;

        $tasks = JobTask::query()
            ->whereIn('job_id', $this->access->assignedJobsQuery($request->user())->select('id'))
            ->tap(fn ($query) => $this->limitToCrew($query, $user))
            ->with(['job:id,name', 'foreman:id,name,initials,role', 'supervisor:id,name,initials,role', 'crew:id,name,initials,role', 'assignments.member'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 50), 100));

        // Each assignee's own sign-off on a job: submitted for review, or approved.
        $completions = JobForemanCompletion::query()
            ->whereIn('job_id', $tasks->getCollection()->pluck('job_id')->unique())
            ->whereIn('foreman_id', $tasks->getCollection()->flatMap(fn (JobTask $task) => $task->runnerIds())->unique())
            ->get()
            ->keyBy(fn (JobForemanCompletion $c) => $c->job_id.':'.$c->foreman_id);

        return $this->ok([
            'tasks' => $tasks->getCollection()->map(fn (JobTask $task) => [
                // The crew member the task is on, and where their own work on this job stands:
                // a foreman approves a journeyman's work once it is submitted.
                'assigneeId' => $task->foreman_id,
                // A foreman who oversees a task still approves its worker; never their own work.
                'assigneeIsMe' => $myForemanId !== null && $task->isRunBy($myForemanId),
                // Everyone the task is given to, with the role each holds on it — the app can
                // show them all; `assigneeId` and the fields around it stay the first runner's.
                'assignees' => $task->crew->map(fn ($person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials,
                    'role' => $person->role,
                    'roleLabel' => $person->roleLabel(),
                    'slot' => $person->pivot->slot,
                ])->values(),
                'assigneeReady' => (bool) $completions->get($task->job_id.':'.$task->foreman_id)?->isReadyForReview()
                    && ! $completions->get($task->job_id.':'.$task->foreman_id)?->isApproved(),
                'assigneeApproved' => (bool) $completions->get($task->job_id.':'.$task->foreman_id)?->isApproved(),
                // Whether this task is the signed-in user's own — what splits
                // "My Tasks" from "Crew Tasks" on the app's task list.
                'isMine' => $this->isMine($task, $user->id, $myForemanId),
                'startsOn' => $task->starts_on?->toDateString(),
                'completionPct' => $task->completion_pct,
                'assigneeRole' => $task->foreman?->roleLabel() ?? $task->supervisor?->roleLabel(),
                'id' => $task->id,
                'jobId' => $task->job_id,
                'jobName' => $task->job?->name ?? '',
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'assigneeName' => $this->assigneeName($task),
                'assigneeInitials' => $this->assigneeInitials($task),
                'dueDate' => $task->ends_on?->toDateString(),
                'createdAt' => $task->created_at?->toISOString(),
            ])->all(),
            'meta' => [
                'currentPage' => $tasks->currentPage(),
                'lastPage' => $tasks->lastPage(),
                'perPage' => $tasks->perPage(),
                'total' => $tasks->total(),
            ],
        ]);
    }

    /**
     * Whose tasks the "Crew Tasks" list may show. A manager reads everyone's. A foreman reads their
     * own and their crew's — the journeymen and apprentices on their team, and the apprentices
     * assigned under those journeymen. A journeyman reads their own and those of the apprentices
     * assigned under them. Nobody else's task appears.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<JobTask>  $query
     */
    private function limitToCrew($query, User $user): void
    {
        $me = $user->foreman;

        if ($user->hasForemanAuthority() && $me?->role !== Foreman::ROLE_FOREMAN) {
            return; // a manager
        }

        if ($me === null) {
            return;
        }

        $crewIds = collect([$me->id]);

        if ($me->role === Foreman::ROLE_FOREMAN) {
            $crew = Foreman::query()->whereIn('role', Foreman::WORKER_ROLES)
                ->when($me->team_id !== null, fn ($q) => $q->where('team_id', $me->team_id), fn ($q) => $q->whereRaw('1 = 0'))
                ->pluck('id');
            $journeymen = Foreman::query()->whereIn('id', $crew)->where('role', Foreman::ROLE_JOURNEYMAN)->pluck('id');
            $crewIds = $crewIds->merge($crew)->merge(
                JobApprenticeAssignment::query()->whereIn('journeyman_id', $journeymen)->pluck('apprentice_id'),
            );
        } elseif ($me->role === Foreman::ROLE_JOURNEYMAN) {
            $crewIds = $crewIds->merge(
                JobApprenticeAssignment::query()->where('journeyman_id', $me->id)->pluck('apprentice_id'),
            );
        }

        $crewIds = $crewIds->unique()->values();
        $crewUserIds = Foreman::query()->whereIn('id', $crewIds)->whereNotNull('user_id')->pluck('user_id')->push($user->id);

        $query->where(fn ($q) => $q
            ->where(fn ($held) => $held->heldByAny($crewIds))
            ->orWhereHas('assignments.member', fn ($m) => $m->whereIn('user_id', $crewUserIds)));
    }

    private function isMine(JobTask $task, int $userId, ?int $foremanId): bool
    {
        if ($foremanId !== null && $task->isHeldBy($foremanId)) {
            return true;
        }

        return $task->assignments->contains(fn ($a) => $a->member?->user_id === $userId);
    }

    private function assigneeName(JobTask $task): ?string
    {
        return $task->foreman?->name
            ?? $task->supervisor?->name
            ?? $task->assignments->first()?->member?->name;
    }

    private function assigneeInitials(JobTask $task): ?string
    {
        return $task->foreman?->initials
            ?? $task->supervisor?->initials
            ?? $task->assignments->first()?->member?->initials;
    }
}
