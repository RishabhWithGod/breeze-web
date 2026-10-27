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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        $scope = $this->crewScope($user);

        $tasks = JobTask::query()
            ->whereIn('job_id', $this->access->assignedJobsQuery($request->user())->select('id'))
            ->tap(fn ($query) => $this->limitToCrew($query, $user, $scope))
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

        // Which journeyman each apprentice works under on each job, so the Crew list can show
        // an apprentice's tasks beneath their journeyman.
        $apprenticeRunnerIds = $tasks->getCollection()->flatMap(fn (JobTask $task) => $task->runnerIds())->unique();
        $under = JobApprenticeAssignment::query()
            ->whereIn('job_id', $tasks->getCollection()->pluck('job_id')->unique())
            ->whereIn('apprentice_id', $apprenticeRunnerIds)
            ->with('journeyman:id,name')
            ->get()
            ->keyBy(fn (JobApprenticeAssignment $a) => $a->job_id.':'.$a->apprentice_id);

        // The apprentices on each job (the user's own crew only, unless they see everyone), so
        // the Crew list can show the job's tasks beneath each apprentice's name.
        $apprenticesByJob = JobApprenticeAssignment::query()
            ->whereIn('job_id', $tasks->getCollection()->pluck('job_id')->unique())
            ->when($scope !== null, fn ($q) => $q->whereIn('journeyman_id', $scope['journeymanIds']))
            ->with(['journeyman:id,name', 'apprentice:id,name'])
            ->get()
            ->groupBy('job_id');

        return $this->ok([
            'tasks' => $tasks->getCollection()->map(fn (JobTask $task) => [
                'apprentices' => ($apprenticesByJob[$task->job_id] ?? collect())
                    ->filter(fn (JobApprenticeAssignment $a) => $a->apprentice !== null)
                    ->map(fn (JobApprenticeAssignment $a) => [
                        'id' => $a->apprentice_id,
                        'name' => $a->apprentice->name,
                        'underName' => $a->journeyman?->name,
                    ])->unique('id')->values(),
                // The journeyman an apprentice runner works under on this job, if the person
                // on the task is an apprentice.
                'underName' => $under->get($task->job_id.':'.$task->foreman_id)?->journeyman?->name,
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
                'isMine' => $mine = $this->isMine($task, $user->id, $myForemanId),
                // Whether the task is also the crew's — someone on the user's team is on it. A task
                // can be both: it shows under My Tasks and Crew Tasks alike.
                'isCrew' => $this->isCrew($task, $user->id, $myForemanId, $scope, $mine),
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
     * Who counts as this user's crew: a foreman's journeymen and apprentices (their team, plus the
     * apprentices assigned under those journeymen) and a journeyman's assigned apprentices — the
     * user themselves included. Null for a manager (everyone) or a user with no crew record
     * (an assignment-only electrician), who are not narrowed to a crew at all.
     *
     * @return array{ids: Collection<int, int>, userIds: Collection<int, int>, journeymanIds: Collection<int, int>, apprenticeJobIds: Collection<int, int>}|null
     */
    private function crewScope(User $user): ?array
    {
        $me = $user->foreman;

        if (($user->hasForemanAuthority() && $me?->role !== Foreman::ROLE_FOREMAN) || $me === null) {
            return null;
        }

        $crewIds = collect([$me->id]);
        $journeymen = collect();

        if ($me->role === Foreman::ROLE_FOREMAN) {
            $crew = Foreman::query()->whereIn('role', Foreman::WORKER_ROLES)
                ->when($me->team_id !== null, fn ($q) => $q->where('team_id', $me->team_id), fn ($q) => $q->whereRaw('1 = 0'))
                ->pluck('id');
            $journeymen = Foreman::query()->whereIn('id', $crew)->where('role', Foreman::ROLE_JOURNEYMAN)->pluck('id');
            $crewIds = $crewIds->merge($crew)->merge(
                JobApprenticeAssignment::query()->whereIn('journeyman_id', $journeymen)->pluck('apprentice_id'),
            );
        } elseif ($me->role === Foreman::ROLE_JOURNEYMAN) {
            $journeymen = collect([$me->id]);
            $crewIds = $crewIds->merge(
                JobApprenticeAssignment::query()->where('journeyman_id', $me->id)->pluck('apprentice_id'),
            );
        }

        $crewIds = $crewIds->unique()->values();
        $crewUserIds = Foreman::query()->whereIn('id', $crewIds)->whereNotNull('user_id')->pluck('user_id')->push($user->id)->unique()->values();

        // The jobs where one of these journeymen has an apprentice: every task on them is the
        // apprentice's work too, whoever it is assigned to.
        $apprenticeJobIds = JobApprenticeAssignment::query()
            ->whereIn('journeyman_id', $journeymen)
            ->pluck('job_id')
            ->unique()
            ->values();

        return ['ids' => $crewIds, 'userIds' => $crewUserIds, 'journeymanIds' => $journeymen->values(), 'apprenticeJobIds' => $apprenticeJobIds];
    }

    /**
     * Whose tasks the list may hold: the user's own and their crew's (see [crewScope]). Nobody
     * else's task appears.
     *
     * @param  Builder<JobTask>  $query
     * @param  array{ids: Collection<int, int>, userIds: Collection<int, int>, journeymanIds: Collection<int, int>, apprenticeJobIds: Collection<int, int>}|null  $scope
     */
    private function limitToCrew($query, User $user, ?array $scope): void
    {
        if ($scope === null) {
            return;
        }

        $query->where(fn ($q) => $q
            ->where(fn ($held) => $held->heldByAny($scope['ids']))
            ->orWhereHas('assignments.member', fn ($m) => $m->whereIn('user_id', $scope['userIds']))
            ->orWhereIn('job_id', $scope['apprenticeJobIds']));
    }

    /** A task given to the user — to run it, or to oversee it. */
    private function isMine(JobTask $task, int $userId, ?int $foremanId): bool
    {
        if ($foremanId !== null && $task->isHeldBy($foremanId)) {
            return true;
        }

        return $task->assignments->contains(fn ($a) => $a->member?->user_id === $userId);
    }

    /**
     * Whether the task is on the crew's plate too: someone other than the user, from the user's
     * crew, is running it. For a user not narrowed to a crew (a manager, an electrician) it is any
     * task that isn't theirs, or one that has somebody else on it as well.
     *
     * @param  array{ids: Collection<int, int>, userIds: Collection<int, int>, journeymanIds: Collection<int, int>, apprenticeJobIds: Collection<int, int>}|null  $scope
     */
    private function isCrew(JobTask $task, int $userId, ?int $foremanId, ?array $scope, bool $mine): bool
    {
        $ints = fn ($ids) => array_map('intval', collect($ids)->all());
        $otherRunners = array_diff($ints($task->runnerIds()), [$foremanId]);
        $otherMembers = array_diff($ints($task->assignments->map(fn ($a) => $a->member?->user_id)->filter()), [$userId]);

        if ($scope === null) {
            return ! $mine || $otherRunners !== [] || $otherMembers !== [];
        }

        return $scope['apprenticeJobIds']->contains($task->job_id)
            || array_intersect($otherRunners, $ints($scope['ids'])) !== []
            || array_intersect($otherMembers, $ints($scope['userIds'])) !== [];
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
