<?php

namespace App\Http\Controllers;

use App\Models\Foreman;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Team;
use App\Models\User;
use App\Policies\JobSchedulePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The crew register, read the way work is staffed: by team.
 *
 * A flat list of names answered "who is free" but never "who is free on the
 * crew that is already on this site". Teams are the grouping; a person's role
 * on one — foreman, journeyman or apprentice — is what they do there.
 *
 * People with no team are not hidden. Everyone on the register before teams
 * existed has none, and so does anyone hired before their crew is decided.
 */
class TeamController extends Controller
{
    public function __construct(private readonly JobSchedulePolicy $policy) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $open = $this->load(closed: false);

        // A team earns its place by matching, or by having someone who does.
        $matching = fn ($query) => $query
            ->when($search !== '', fn ($inner) => $inner->where('name', 'like', "%{$search}%"));

        $teams = Team::query()
            ->when($search !== '', fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhereHas('members', $matching))
            ->with(['members' => fn ($query) => $query->when(
                $search !== '',
                // Only when the search matched a person: a team matched by its
                // own name shows its whole crew.
                fn ($inner) => $inner->where(fn ($q) => $q
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('team', fn ($t) => $t->where('name', 'like', "%{$search}%"))),
            )])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'members' => $team->members->map(fn (Foreman $member) => $this->row($member, $open))->values(),
            ]);

        return Inertia::render('Teams', [
            // Wrapped, not handed over raw: a bare paginator serialises flat
            // and the screen reads `meta.current_page` to draw its pager.
            'teams' => JsonResource::collection($teams),
            /*
             * Everyone on no crew, listed under the teams rather than left out.
             * Only on the first page: it is one group, not a page's worth, and
             * repeating it under every page would read as a per-page section.
             */
            'unassigned' => $teams->currentPage() > 1 ? [] : Foreman::query()
                ->whereNull('team_id')
                ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->get()
                ->map(fn (Foreman $member) => $this->row($member, $open))
                ->values(),
            'filters' => ['search' => $search],
            'canManage' => $this->canManage($request->user()),
            /*
             * Technicians who signed up from the mobile app, on this same
             * page rather than a separate one — a manager approving someone
             * and staffing a crew is one job, not two screens.
             */
            'pendingTechnicians' => $this->technicians(User::STATUS_PENDING_APPROVAL),
            'activeTechnicians' => $this->technicians(User::STATUS_ACTIVE),
            'rejectedTechnicians' => $this->technicians(User::STATUS_REJECTED),
            'canApproveTechnicians' => $this->canApproveTechnicians($request->user()),
            // Every team, unpaginated — the "assign a team" picker on a
            // pending technician's row needs the whole list regardless of
            // which page of `teams` above happens to be showing.
            'teamOptions' => Team::query()->orderBy('name')->get(['id', 'name']),
            'technicianRoleOptions' => TechnicianController::ROLES,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function technicians(string $status): Collection
    {
        return User::query()
            ->where('registration_source', User::SOURCE_MOBILE)
            ->where('status', $status)
            // Once a technician has a team and a role, `TechnicianController`
            // syncs them onto the real `foremen` roster — from then on their
            // team's own card is where they live, not this separate list.
            ->whereDoesntHave('foreman')
            ->with(['teamMember.team', 'approver'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                // What they actually are — 'Foreman' is only ever the
                // starting default a manager may not have corrected yet.
                'role' => $user->role,
                'approvedAt' => $user->approved_at?->toISOString(),
                'approvedBy' => $user->approver?->name,
                'teamMember' => $user->teamMember ? [
                    'id' => $user->teamMember->id,
                    'teamId' => $user->teamMember->team_id,
                    'teamName' => $user->teamMember->team?->name,
                ] : null,
                'createdAt' => $user->created_at?->toISOString(),
            ])
            ->values();
    }

    private const TECHNICIAN_MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    private function canApproveTechnicians(?User $user): bool
    {
        return $user !== null
            && in_array(mb_strtolower(trim((string) $user->role)), self::TECHNICIAN_MANAGER_ROLES, true);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->canManage($request->user()), 403);

        return Inertia::render('TeamCreate');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                Rule::unique('teams', 'name'),
            ],
        ], [
            'name.required' => 'Name this team',
            'name.unique' => 'A team with that name already exists',
        ]);

        $team = Team::create(['name' => $data['name']]);

        /*
         * Added from inside another form — a job that needs a crew the register
         * does not have yet. Sending someone to the register and back would
         * lose everything they had typed, so they stay where they are and the
         * new crew arrives in the refreshed props.
         */
        if ($request->boolean('inline')) {
            return back()->with('success', "“{$team->name}” was added.");
        }

        return redirect()
            ->route('teams.index')
            ->with('success', "“{$team->name}” was added. Add its members next.");
    }

    /**
     * One member, as a row of the register.
     *
     * @param  Collection<int, object>  $open
     * @return array<string, mixed>
     */
    private function row(Foreman $member, Collection $open): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'initials' => $member->initials,
            'role' => $member->role,
            'roleLabel' => $member->roleLabel(),
            /*
             * Open work, not lifetime totals. "Who has room" is the whole
             * question this list answers, and someone who finished forty tasks
             * last year is as free as someone who has never had any.
             */
            'openTasks' => (int) ($open[$member->id]->tasks ?? 0),
            'openJobs' => (int) ($open[$member->id]->jobs ?? 0),
            'openHours' => (float) ($open[$member->id]->hours ?? 0),
            'phone' => $member->phone,
            'email' => $member->email,
            'licenceNumber' => $member->licence_number,
            'joinedOn' => $member->started_on?->toDateString(),
        ];
    }

    /**
     * What everyone is carrying, in one grouped query rather than one per row.
     *
     * Tasks on a deleted job are not work anyone is carrying, so they are left
     * out the same way the task list leaves them out.
     *
     * @return Collection<int, object>
     */
    private function load(bool $closed): Collection
    {
        // Foreman, journeyman or apprentice: all are carrying the task — see
        // JobTask::workload().
        return JobTask::workload($closed);
    }

    private function canManage(?User $user): bool
    {
        return $user !== null && $this->policy->createTask($user, new JobSchedule);
    }
}
