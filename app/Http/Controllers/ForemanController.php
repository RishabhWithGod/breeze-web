<?php

namespace App\Http\Controllers;

use App\Models\Foreman;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Team;
use App\Models\User;
use App\Policies\JobSchedulePolicy;
use App\Rules\UsPhoneNumber;
use App\Support\UsPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The foremen a task can be handed to.
 *
 * A short register, not a module: a foreman is a name and a set of initials,
 * and everything else about them — what they are running, how loaded they are —
 * is a fact about their tasks rather than about them.
 */
class ForemanController extends Controller
{
    public function __construct(private readonly JobSchedulePolicy $policy) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        $open = $this->load(closed: false);

        $foremen = Foreman::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Foreman $foreman) => [
                'id' => $foreman->id,
                'name' => $foreman->name,
                'initials' => $foreman->initials,
                /*
                 * Open work, not lifetime totals. "Who has room" is the whole
                 * question this list answers, and a foreman who finished forty
                 * tasks last year is as free as one who has never had any.
                 */
                'openTasks' => (int) ($open[$foreman->id]->tasks ?? 0),
                'openJobs' => (int) ($open[$foreman->id]->jobs ?? 0),
                'openHours' => (float) ($open[$foreman->id]->hours ?? 0),
                'phone' => $foreman->phone,
                'email' => $foreman->email,
                'licenceNumber' => $foreman->licence_number,
                'joinedOn' => $foreman->started_on?->toDateString(),
            ]);

        return Inertia::render('Foremen', [
            // Wrapped, not handed over raw: a bare paginator serialises flat
            // and the screen reads `meta.current_page` to draw its pager.
            'foremen' => JsonResource::collection($foremen),
            'filters' => ['search' => $search],
            'canManage' => $this->canManage($request->user()),
        ]);
    }

    /**
     * One foreman, and everything on record about them.
     *
     * The register itself only answers "who has room". This answers the
     * questions you have once you have picked someone: how to reach them, what
     * lets them sign off work, and exactly what they are carrying.
     */
    public function show(Foreman $foreman): Response
    {
        $foreman->loadMissing('team');

        $open = $this->load(closed: false)[$foreman->id] ?? null;
        $done = $this->load(closed: true)[$foreman->id] ?? null;

        $tasks = JobTask::query()
            // Work they are running and work they are over: the register row
            // above counts both, so the list below has to show both.
            ->heldBy($foreman->id)
            ->whereHas('job')
            ->whereNotIn('status', JobTask::CLOSED_STATUSES)
            ->with('job:id,name,client')
            ->orderByDesc('job_id')
            ->orderBy('id')
            ->get();

        return Inertia::render('ForemanShow', [
            'foreman' => [
                'id' => $foreman->id,
                'name' => $foreman->name,
                'initials' => $foreman->initials,
                'role' => $foreman->role,
                'roleLabel' => $foreman->roleLabel(),
                'team' => $foreman->team === null ? null : [
                    'id' => $foreman->team->id,
                    'name' => $foreman->team->name,
                ],
                'phone' => $foreman->phone,
                'email' => $foreman->email,
                'licenceNumber' => $foreman->licence_number,
                'joinedOn' => $foreman->started_on?->toDateString(),
                'notes' => $foreman->notes,
                'openTasks' => (int) ($open->tasks ?? 0),
                'openJobs' => (int) ($open->jobs ?? 0),
                'openHours' => (float) ($open->hours ?? 0),
                'completedTasks' => (int) ($done->tasks ?? 0),
            ],
            /*
             * Open work only, and listed rather than counted: the count is on
             * the register already, and what you came here for is which jobs it
             * is on. Tasks on a deleted job are nobody's work to carry.
             */
            'tasks' => $tasks->map(fn (JobTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'estimatedHours' => $task->estimated_hours === null
                    ? null
                    : (float) $task->estimated_hours,
                'jobId' => $task->job_id,
                'jobName' => $task->job?->name,
                'client' => $task->job?->client,
                /*
                 * Which hat they wear on this one. The list now mixes work they
                 * are running with work they are over, and those are different
                 * obligations — a row that does not say which is a row nobody
                 * can act on.
                 */
                'heldAs' => $task->foreman_id === $foreman->id ? 'foreman' : 'supervisor',
            ])->values(),
            'canManage' => $this->canManage(request()->user()),
        ]);
    }

    /**
     * What every foreman is carrying, in one grouped query rather than one per
     * row. Tasks on a deleted job are not work anyone is carrying, so they are
     * left out here the same way the task list leaves them out.
     *
     * @return Collection<int, object>
     */
    private function load(bool $closed): Collection
    {
        // Foreman or supervisor: both are carrying the task — see
        // JobTask::workload().
        return JobTask::workload($closed);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->canManage($request->user()), 403);

        return Inertia::render('ForemanCreate', $this->formProps());
    }

    /**
     * What the add and edit forms both need: the crews, and the roles.
     *
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        return [
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'roles' => array_map(
                fn (string $role) => ['value' => $role, 'label' => ucfirst($role)],
                Foreman::ROLES,
            ),
        ];
    }

    public function edit(Request $request, Foreman $foreman): Response
    {
        abort_unless($this->canManage($request->user()), 403);

        return Inertia::render('ForemanEdit', [
            ...$this->formProps(),
            'foreman' => [
                'id' => $foreman->id,
                'name' => $foreman->name,
                'initials' => $foreman->initials,
                'role' => $foreman->role,
                'teamId' => $foreman->team_id,
                'phone' => $foreman->phone,
                'email' => $foreman->email,
                'licenceNumber' => $foreman->licence_number,
                'joinedOn' => $foreman->started_on?->toDateString(),
                'notes' => $foreman->notes,
            ],
        ]);
    }

    public function update(Request $request, Foreman $foreman): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $this->validated($request, $foreman);

        $foreman->update([
            ...$this->details($data),
            'name' => $data['name'],
            /*
             * Re-derived from the new name rather than left as it was: initials
             * are never typed, so a renamed foreman whose initials still spelt
             * the old name would be a record disagreeing with itself.
             */
            'initials' => $this->initialsFor($data['name']),
        ]);

        return redirect()
            ->route('foremen.show', $foreman)
            ->with('success', "“{$foreman->name}” was updated.");
    }

    /**
     * Removing a foreman from the register.
     *
     * Only one who has never been handed anything. `job_tasks.foreman_id` is
     * `SET NULL`, so deleting anyone else would quietly rewrite the record of
     * who ran their work — the delete would look tidy and lose history.
     */
    public function destroy(Request $request, Foreman $foreman): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $taskCount = JobTask::where('foreman_id', $foreman->id)->count();

        if ($taskCount > 0) {
            return back()->with(
                'warning',
                "“{$foreman->name}” has run ".$taskCount.' '.str('task')->plural($taskCount).
                ', so removing them would erase who did that work. Hand the tasks to '.
                'someone else first.',
            );
        }

        $name = $foreman->name;
        $foreman->delete();

        return redirect()
            ->route('teams.index')
            ->with('warning', "“{$name}” was removed from the register.");
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request->user()), 403);

        $data = $this->validated($request);

        $foreman = Foreman::create([
            ...$this->details($data),
            'name' => $data['name'],
            /*
             * Always derived, never asked for. Two words give "DW", and a field
             * the app can fill in itself is one more thing to type and one more
             * thing for two records to disagree about.
             */
            'initials' => $this->initialsFor($data['name']),
        ]);

        /*
         * Added from inside another form — a task that needs someone the crew
         * does not have on it yet. Sending the planner to the register and back
         * would lose everything they had typed, so they stay where they are and
         * the new person arrives in the refreshed props.
         */
        if ($request->boolean('inline')) {
            return back()->with('success', "“{$foreman->name}” was added.");
        }

        return redirect()
            ->route('teams.index')
            ->with('success', "“{$foreman->name}” was added.");
    }

    /**
     * The same rules whether the foreman is being added or corrected.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Foreman $foreman = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'min:2', 'max:120',
                // A foreman renaming themselves is not a clash with themselves.
                Rule::unique('foremen', 'name')->ignore($foreman),
            ],
            /*
             * Everything below is optional. A foreman exists to be handed work,
             * and none of this is needed to do that — it is what you reach for
             * once they have it.
             */
            // Typed however the person types it — brackets, dashes, +1 — and
            // stored the one way the app reads it. See UsPhoneNumber.
            /*
             * What they do on the crew. Required, because "a member" is not a
             * job — the whole point of the register is knowing who supervises
             * and who runs the work.
             */
            'role' => ['required', Rule::in(Foreman::ROLES)],
            /*
             * Which crew they are on. Optional: somebody can be hired before
             * their team is decided, and the register shows them as exactly
             * that rather than inventing one.
             */
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'phone' => ['nullable', 'string', 'max:40', new UsPhoneNumber],
            'email' => ['nullable', 'email', 'max:255'],
            'licence_number' => ['nullable', 'string', 'max:60'],
            'started_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'name.required' => 'Enter the member’s name',
            'name.unique' => 'Someone with that name is already on the register',
            'role.required' => 'Pick what they do on the crew',
            'email.email' => 'That does not look like an email address',
        ]);
    }

    /**
     * The optional half of the record, blanks normalised to nothing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return [
            /*
             * Blank stays null rather than an empty string, so "has a phone
             * number" is one check everywhere rather than two — and what is
             * kept is normalised, so every screen, export and job sheet reads
             * the same shape without formatting it again.
             */
            'role' => $data['role'],
            'team_id' => $data['team_id'] ?? null,
            'phone' => UsPhone::format($this->orNull($data['phone'] ?? null)),
            'email' => $this->orNull($data['email'] ?? null),
            'licence_number' => $this->orNull($data['licence_number'] ?? null),
            // The date of joining — nullable, because plenty of crews cannot
            // say and the register is still useful without it.
            'started_on' => $data['started_on'] ?? null,
            'notes' => $this->orNull($data['notes'] ?? null),
        ];
    }

    /** An untyped optional field is nothing, not an empty string. */
    private function orNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** First letters of the first two words, which is what a name gives you. */
    private function initialsFor(string $name): string
    {
        return str($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('') ?: mb_strtoupper(mb_substr($name, 0, 2));
    }

    /**
     * Adding a foreman is a planning decision — the same one that staffs a
     * task, which is the only thing a foreman is for.
     */
    private function canManage(?User $user): bool
    {
        return $user !== null && $this->policy->createTask($user, new JobSchedule);
    }
}
