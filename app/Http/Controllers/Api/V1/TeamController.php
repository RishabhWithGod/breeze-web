<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Controllers\TechnicianController;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\CompanyProfile;
use App\Models\Team;
use App\Models\User;
use App\Rules\UsPhoneNumber;
use App\Services\Billing\SubscriptionSummary;
use App\Services\BreezeBucks\BreezeBucksLedger;
use App\Services\Company\ManagerRegistrar;
use App\Services\TimeTracking\TeamMemberResolver;
use App\Support\CompanyRule;
use App\Support\UsPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * The crew register, as the mobile app sees it — the same `foremen` table
 * and `registration_source = mobile` pending-signup query web's
 * `TeamController::index`/`technicians()` use. Reading the roster is open to
 * any signed-in, active mobile account (same as web: the Team page itself
 * has no extra role gate); adding, editing or removing a member is
 * manager-only, enforced inside each action the same way
 * `Api\V1\TechnicianController` gates approve/reject.
 *
 * `teamOptions`/`roleOptions` ride along on the index payload rather than a
 * separate endpoint — they're needed alongside `pendingApprovals` to fill
 * the approve action's team/role picker, and now alongside the roster too,
 * to fill the same Add/Edit Member form web's `ForemanController::formProps`
 * hands over.
 */
class TeamController extends Controller
{
    use ApiResponses;

    private const MANAGER_ROLES = ['project manager', 'admin', 'owner'];

    /** The one role on the Add Member form that is not a crew role. */
    private const MANAGER_ROLE = 'manager';

    public function __construct(private readonly BreezeBucksLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $open = $this->workload(closed: false);

        $members = Foreman::query()
            ->with('team:id,name')
            ->orderByDesc('started_on')
            ->orderByDesc('id')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        $pendingApprovals = User::query()
            ->where('registration_source', User::SOURCE_MOBILE)
            ->where('status', User::STATUS_PENDING_APPROVAL)
            ->whereDoesntHave('foreman')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'createdAt' => $user->created_at?->toISOString(),
            ])
            ->all();

        return $this->ok([
            'members' => $members->getCollection()->map(fn (Foreman $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'initials' => $member->initials,
                'role' => $member->role,
                'roleLabel' => $member->roleLabel(),
                'teamId' => $member->team_id,
                'teamName' => $member->team?->name,
                'phone' => $member->phone,
                'email' => $member->email,
                'licenceNumber' => $member->licence_number,
                'joinedOn' => $member->started_on?->toDateString(),
                // Open work, not lifetime totals — "who has room" is the
                // question the register answers, same reasoning
                // `ForemanController::index` gives.
                'openTasks' => (int) ($open[$member->id]->tasks ?? 0),
                'openJobs' => (int) ($open[$member->id]->jobs ?? 0),
                'openHours' => (float) ($open[$member->id]->hours ?? 0),
            ])->all(),
            'pendingApprovals' => $pendingApprovals,
            'teamOptions' => Team::query()->orderBy('name')->get(['id', 'name']),
            'roleOptions' => TechnicianController::ROLES,
            /*
             * What the Add/Edit Member form needs — the crews, and the
             * roles a register row itself can hold (`Foreman::ROLES`,
             * lowercase), distinct from `roleOptions` above
             * (`TechnicianController::ROLES`, capitalised — the approve
             * picker's own vocabulary).
             */
            'memberRoles' => [
                ...array_map(
                    fn (string $role) => ['value' => $role, 'label' => ucfirst($role)],
                    Foreman::ROLES,
                ),
                ...(app(ManagerRegistrar::class)->canAdd($request->user())
                    ? [['value' => self::MANAGER_ROLE, 'label' => 'Manager']]
                    : []),
            ],
            'canManage' => $this->canManage($request->user()),
            // The company's managers, for the Managers section — the owner's to see.
            'managers' => $this->managers($request->user()),
            // How many seats are left, so Add Member can say so before it is filled in.
            'seats' => $this->seats($request->user()),
            'meta' => [
                'currentPage' => $members->currentPage(),
                'lastPage' => $members->lastPage(),
                'perPage' => $members->perPage(),
                'total' => $members->total(),
            ],
        ]);
    }

    /**
     * A roster member's detail — everything `index` already sends for this
     * row, plus their Breeze Bucks balance, the jobs they're linked to, and
     * — matching web's `ForemanShow` — the open tasks they are actually
     * carrying right now (title/status/hours/job/client/heldAs).
     */
    public function show(Request $request, Foreman $member): JsonResponse
    {
        $member->load(['team:id,name', 'user', 'jobs' => fn ($query) => $query->orderByDesc('start_date')]);

        $open = $this->workload(closed: false)[$member->id] ?? null;
        $done = $this->workload(closed: true)[$member->id] ?? null;

        $tasks = JobTask::query()
            ->inCompanyOf($request->user())
            ->heldBy($member->id)
            ->whereHas('job')
            ->whereNotIn('status', JobTask::CLOSED_STATUSES)
            ->with('job:id,name,client')
            ->orderByDesc('job_id')
            ->orderBy('id')
            ->get();

        return $this->ok([
            'id' => $member->id,
            'name' => $member->name,
            'initials' => $member->initials,
            'role' => $member->role,
            'roleLabel' => $member->roleLabel(),
            'teamId' => $member->team_id,
            'teamName' => $member->team?->name,
            'phone' => $member->phone,
            'email' => $member->email,
            'licenceNumber' => $member->licence_number,
            'notes' => $member->notes,
            'joinedOn' => $member->started_on?->toDateString(),
            'breezeBucksBalance' => $member->user ? $this->ledger->balanceFor($member->user) : 0,
            'openTasks' => (int) ($open->tasks ?? 0),
            'openJobs' => (int) ($open->jobs ?? 0),
            'openHours' => (float) ($open->hours ?? 0),
            'completedTasks' => (int) ($done->tasks ?? 0),
            'jobs' => $member->jobs->map(fn (Job $job) => [
                'id' => $job->id,
                'name' => $job->name,
                'status' => $job->status,
                'startDate' => $job->start_date?->toDateString(),
            ])->all(),
            'tasks' => $tasks->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'estimatedHours' => $task->estimated_hours === null ? null : (float) $task->estimated_hours,
                'jobId' => $task->job_id,
                'jobName' => $task->job?->name,
                'client' => $task->job?->client,
                'heldAs' => $member->role,
            ])->values(),
            'canManage' => $this->canManage($request->user()),
        ]);
    }

    /**
     * Adds a member to the register — same rules and the same side effect
     * as web's `ForemanController::store`: every member added here also
     * gets a real mobile-app account, since `email`+`password` are that
     * account's own login.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $this->validated($request);

        // A manager is not crew: they get a login into the company, and no place on the crew register.
        if (($data['role'] ?? null) === self::MANAGER_ROLE) {
            $manager = app(ManagerRegistrar::class)->add($request->user(), $data, 'email');

            return $this->created(['id' => null, 'managerId' => $manager->id], "\u{201c}{$manager->name}\u{201d} was added as a manager.");
        }

        $data['started_on'] ??= now()->toDateString();

        $member = Foreman::create([
            ...$this->details($data),
            'name' => $data['name'],
            'initials' => $this->initialsFor($data['name']),
        ]);

        $this->createMobileAccount($member, $data);

        return $this->ok(['id' => $member->id], "\u{201c}{$member->name}\u{201d} was added.");
    }

    /** Corrects a register row — same rules as `store`, minus the login fields. */
    public function update(Request $request, Foreman $member): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $this->validated($request, $member);

        $member->update([
            ...$this->details($data),
            'name' => $data['name'],
            'initials' => $this->initialsFor($data['name']),
        ]);

        $this->syncLoginEmail($member, $data['email'] ?? null);

        return $this->ok(message: "\u{201c}{$member->name}\u{201d} was updated.");
    }

    /**
     * Removes a member from the register — only one who has never been
     * handed anything. `job_tasks.foreman_id` is `SET NULL` on delete, so
     * removing anyone else would quietly rewrite the record of who ran
     * their work; this refuses instead, same as web's
     * `ForemanController::destroy`.
     */
    public function destroy(Request $request, Foreman $member): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $taskCount = JobTask::runBy($member->id)->count();

        if ($taskCount > 0) {
            return $this->fail(
                "\u{201c}{$member->name}\u{201d} has run {$taskCount} ".str('task')->plural($taskCount).
                ', so removing them would erase who did that work. Hand the tasks to someone else first.',
                422,
            );
        }

        $name = $member->name;
        $member->delete();

        return $this->ok(message: "\u{201c}{$name}\u{201d} was removed from the register.");
    }

    /**
     * Creates a crew group itself — a name, nothing else. Distinct from
     * `store()` above, which adds a *person* to one; mirrors web's own
     * separate `TeamController::store` (`routes/web.php`'s `teams.store`,
     * reached from "Add Team", not "Add Member").
     */
    public function storeTeam(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', CompanyRule::unique('teams', 'name')],
        ], [
            'name.required' => 'Name this team',
            'name.unique' => 'A team with that name already exists',
        ]);

        $team = Team::create(['name' => $data['name']]);

        return $this->ok(['id' => $team->id, 'name' => $team->name], "\u{201c}{$team->name}\u{201d} was added.");
    }

    /** @return array{used: int, limit: int, byRole: array<int, mixed>}|null */
    private function seats(User $user): ?array
    {
        try {
            $seats = app(SubscriptionSummary::class)->build($user)['seats'];

            return ['used' => (int) $seats['used'], 'limit' => (int) $seats['limit'], 'byRole' => $seats['byRole']];
        } catch (\Throwable) {
            return null;
        }
    }

    /** `PUT /teams/{team}` — renames a crew. */
    public function updateTeam(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', CompanyRule::unique('teams', 'name')->ignore($team)],
        ], [
            'name.required' => 'Name this team',
            'name.unique' => 'A team with that name already exists',
        ]);

        $team->update(['name' => $data['name']]);

        return $this->ok(['id' => $team->id, 'name' => $team->name], "\u{201c}{$team->name}\u{201d} was renamed.");
    }

    /**
     * `DELETE /teams/{team}` — only an empty crew, so nobody is stripped of their
     * team. Work it was on is kept; it just stops naming a crew.
     */
    public function destroyTeam(Request $request, Team $team): JsonResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        if ($team->members()->exists()) {
            return $this->fail("\u{201c}{$team->name}\u{201d} still has people on it. Move or remove them first.", 422);
        }

        $clients = \App\Models\Client::query()->where('team_id', $team->id)->count();
        $jobs = Job::query()->where('team_id', $team->id)->count();

        $name = $team->name;
        $team->delete();

        $left = collect([
            $jobs > 0 ? $jobs.' '.str('job')->plural($jobs) : null,
            $clients > 0 ? $clients.' '.str('client')->plural($clients) : null,
        ])->filter()->implode(' and ');

        return $this->ok(null, "\u{201c}{$name}\u{201d} was deleted.".($left === '' ? '' : " {$left} that had this crew now have none."));
    }

    /**
     * Everyone who manages this person's company, the one who set it up first.
     *
     * @return list<array<string, mixed>>
     */
    private function managers(User $viewer): array
    {
        if (! app(ManagerRegistrar::class)->canAdd($viewer)) {
            return [];
        }

        $ownerId = CompanyProfile::query()->whereKey($viewer->company_id)->value('user_id');

        return User::query()
            ->where('company_id', $viewer->company_id)
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', self::MANAGER_ROLES)
            ->orderBy('created_at')
            ->get()
            ->map(fn (User $manager) => [
                'id' => $manager->id,
                'name' => $manager->name,
                'email' => $manager->email,
                'phone' => $manager->phone,
                'role' => $manager->role,
                'isOwner' => $manager->id === $ownerId,
                'isYou' => $manager->id === $viewer->id,
            ])
            ->sortByDesc('isOwner')
            ->values()
            ->all();
    }

    /** @return Collection<int, object> */
    private function workload(bool $closed): Collection
    {
        $viewer = request()->user();

        // Only the work on the jobs of the company they belong to.
        return JobTask::workload($closed, fn ($query) => $query->inCompanyOf($viewer));
    }

    private function createMobileAccount(Foreman $member, array $data): void
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Hash::make($data['password']),
            'role' => ucfirst($data['role']),
            'phone' => $member->phone,
        ]);

        // Their login belongs to the company that added them.
        $user->company_id = $member->company_id;
        $user->save();

        $member->user_id = $user->id;
        $member->save();

        app(TeamMemberResolver::class)->resolveFor($user)->update(['team_id' => $member->team_id]);
    }

    /**
     * A member with a mobile login signs in with the email on this register, so
     * correcting it here has to correct the login too — otherwise they would
     * still have to sign in with the old address. A member without a login keeps
     * it as a plain contact detail.
     *
     * @return list<\Illuminate\Validation\Rules\Unique>
     */
    private function loginEmailRules(?Foreman $member): array
    {
        return $member?->user_id
            ? [Rule::unique('users', 'email')->ignore($member->user_id)]
            : [];
    }

    private function syncLoginEmail(Foreman $member, ?string $email): void
    {
        if (! $member->user_id || blank($email)) {
            return;
        }

        $user = $member->user;
        $email = Str::lower($email);

        if ($user && $user->email !== $email) {
            $user->forceFill(['email' => $email])->save();
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Foreman $member = null): array
    {
        $isCreate = $member === null;

        return $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                CompanyRule::unique('foremen', 'name')->ignore($member),
            ],
            // A manager joins the company rather than a crew — offered only to someone who has a company to add them to.
            'role' => ['required', Rule::in($isCreate && app(ManagerRegistrar::class)->canAdd($request->user())
                ? [...Foreman::ROLES, self::MANAGER_ROLE]
                : Foreman::ROLES)],
            'team_id' => ['nullable', 'integer', CompanyRule::exists('teams')],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'email' => $isCreate
                ? ['required', 'email', 'max:255', Rule::unique('users', 'email')]
                : ['nullable', 'email', 'max:255', ...$this->loginEmailRules($member)],
            'licence_number' => ['nullable', 'string', 'max:60'],
            'started_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...($isCreate ? ['password' => ['required', 'confirmed', Password::defaults()]] : []),
        ], [
            'name.required' => 'Enter the member\u{2019}s name',
            'name.unique' => 'Someone with that name is already on the register',
            'role.required' => 'Pick what they do on the crew',
            'email.email' => 'That does not look like an email address',
            'email.required' => 'Enter the email this member will sign into the mobile app with.',
            'email.unique' => 'Someone already has a mobile account with that email.',
            'password.required' => 'Set a password for this member\u{2019}s mobile app login.',
        ]);
    }

    /** @return array<string, mixed> */
    private function details(array $data): array
    {
        return [
            'role' => $data['role'],
            'team_id' => $data['team_id'] ?? null,
            'phone' => UsPhone::format($this->orNull($data['phone'] ?? null)),
            'email' => $this->orNull($data['email'] ?? null),
            'licence_number' => $this->orNull($data['licence_number'] ?? null),
            'started_on' => $data['started_on'] ?? null,
            'notes' => $this->orNull($data['notes'] ?? null),
        ];
    }

    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function initialsFor(string $name): string
    {
        return str($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: mb_strtoupper(mb_substr($name, 0, 2));
    }

    private function canManage(?User $user): bool
    {
        return $user !== null
            && in_array(mb_strtolower(trim((string) $user->role)), self::MANAGER_ROLES, true);
    }
}
