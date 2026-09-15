<?php

namespace App\Models;

use App\Events\JobStatusChanged;
use App\Notifications\JobReviewStatusChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class Job extends Model
{
    use SoftDeletes;

    /** `jobs` belongs to Laravel's queue driver. */
    protected $table = 'work_jobs';

    public const STATUSES = [
        'draft',
        'planning',
        'scheduled',
        'in-progress',
        'on-hold',
        'delayed',
        'completed',
    ];

    public const STATUS_COMPLETED = 'completed';

    public const TYPES = ['residential', 'commercial', 'industrial'];

    /** Ranked high → low; the unassigned queue's default order. */
    public const PRIORITIES = ['high', 'medium', 'low'];

    /** Sort keys accepted by `scopeSortedForScheduling`. */
    public const SCHEDULING_SORTS = [
        'start-desc',
        'priority-desc',
        'priority-asc',
        'hours-desc',
        'hours-asc',
        'value-desc',
        'created-desc',
        'name-asc',
    ];

    /** Sort keys accepted by `scopeSorted`, mirrored by JOB_SORT_OPTIONS. */
    public const SORTS = [
        'recent',
        'name-asc',
        'start-asc',
        'start-desc',
        'budget-desc',
        'budget-asc',
    ];

    protected $fillable = [
        'user_id',
        'foreman_id',
        'team_id',
        'project_id',
        'client_id',
        'ai_result_id',
        'name',
        'client',
        'location',
        'latitude',
        'longitude',
        'geofence_radius',
        'place_id',
        'description',
        'job_type',
        'status',
        'priority',
        'estimated_hours',
        'required_skills',
        'start_date',
        'end_date',
        'budget',
        'create_estimate',
        'assign_team',
        'notify_client',
        'archived_at',
        'symbol_counts',
        'boq',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'geofence_radius' => 'integer',
            'budget' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            // `actual_hours`/`delay_hours`/`delay_reason` are deliberately not
            // in `$fillable` — they are only ever computed and written by
            // `Api\V1\JobController::changeStatus()` when a job is marked
            // completed, never accepted directly from a request body.
            'actual_hours' => 'decimal:2',
            'delay_hours' => 'decimal:2',
            // Also deliberately not in `$fillable` — only ever set by
            // `markReadyForReview()`/`clearReadyForReview()` below.
            'ready_for_review_at' => 'datetime',
            'required_skills' => 'array',
            'create_estimate' => 'boolean',
            'assign_team' => 'boolean',
            'notify_client' => 'boolean',
            'archived_at' => 'datetime',
            'symbol_counts' => 'array',
            'boq' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Who this job belongs to, read off its project — every job is required
     * to have one (see `StoreJobRequest`), so this never has to fall back to
     * the client the way `Estimate`'s and `Invoice`'s do.
     */
    protected static function booted(): void
    {
        static::saving(function (self $job): void {
            if ($job->user_id !== null && ! $job->isDirty('project_id')) {
                return;
            }

            $job->user_id = $job->project_id === null
                ? null
                : Project::whereKey($job->project_id)->value('user_id');
        });
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Foreman, $this> */
    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Foreman::class);
    }

    /**
     * The crew this job is handed to.
     *
     * What narrows every later choice: the foreman running a task and the
     * supervisor over it are both picked from here rather than from the whole
     * register. Null for a job raised before its crew was decided.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The foremen actually on this job, named once each.
     *
     * They are assigned per task now — a job is not one person's — so this
     * gathers them from the work rather than from the job's own column. That
     * column is only read when there are no tasks to read from: jobs raised
     * before the change still carry one, and it is the only answer they have.
     *
     * @return list<array{name: string, initials: string}>
     */
    public function assignedForemen(): array
    {
        $fromTasks = $this->tasks
            ->pluck('foreman')
            ->filter()
            ->unique('id')
            ->values();

        $foremen = $fromTasks->isNotEmpty()
            ? $fromTasks
            : collect([$this->foreman])->filter();

        return self::named($foremen);
    }

    /**
     * The supervisors over this job's work, named once each.
     *
     * No fallback: supervisors only ever existed on tasks, so a job with none
     * has none — there is no older column to read instead.
     *
     * @return list<array{name: string, initials: string}>
     */
    public function assignedSupervisors(): array
    {
        return self::named(
            $this->tasks->pluck('supervisor')->filter()->unique('id')->values(),
        );
    }

    /**
     * @param  Collection<int, Foreman>  $people
     * @return list<array{name: string, initials: string}>
     */
    private static function named(Collection $people): array
    {
        return $people
            ->map(fn (Foreman $person) => [
                'name' => $person->name,
                'initials' => $person->initials,
            ])
            ->all();
    }

    /** The takeoff this job was created from, when it came from one. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The client on file, by id rather than the `client` name snapshot.
     *
     * Named `clientRecord` rather than `client` because that name is already
     * the string column — a relation of the same name would never be reached.
     *
     * @return BelongsTo<Client, $this>
     */
    public function clientRecord(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** The manager this job belongs to. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Only this manager's own jobs. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** @return BelongsTo<AiResult, $this> */
    public function aiResult(): BelongsTo
    {
        return $this->belongsTo(AiResult::class);
    }

    /** Every assignment ever made, newest first — this is the history. */
    public function assignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class)->latest('id');
    }

    /** @return HasMany<JobAssignment, $this> */
    public function activeAssignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class)->whereNull('released_at')->orderBy('role');
    }

    /** True when the job's quantities came from a reviewed takeoff. */
    public function isFromTakeoff(): bool
    {
        return $this->ai_result_id !== null;
    }

    /**
     * The client sites this job is at. A job can run across more than one.
     *
     * `location` on the job is the first one's snapshot — a printed job sheet
     * must not change when someone later edits the client's address book.
     *
     * @return BelongsToMany<ClientAddress, $this>
     */
    public function addresses(): BelongsToMany
    {
        return $this->belongsToMany(ClientAddress::class, 'job_addresses')
            ->withPivot('position')
            ->orderBy('job_addresses.position');
    }

    /** @return BelongsToMany<TeamMember, $this> */
    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'job_team_member')
            ->withPivot('role_on_job')
            ->withTimestamps()
            ->orderBy('name');
    }

    /** @return HasMany<Estimate, $this> */
    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class)->latest('issued_on');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('invoice_date');
    }

    /** Real actual material/equipment/other costs logged against this job. */
    public function costEntries(): HasMany
    {
        return $this->hasMany(JobCostEntry::class)->latest('incurred_on');
    }

    /** Crew shifts on the scheduling calendar, earliest first. */
    public function crewShifts(): HasMany
    {
        return $this->hasMany(CrewShift::class)
            ->orderBy('scheduled_date')
            ->orderBy('start_time');
    }

    /**
     * The schedule this job runs to — its window, working week and progress.
     *
     * One per job. Distinct from `crewShifts()`, which is who is on site on which
     * day; this is the plan those shifts serve.
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(JobSchedule::class);
    }

    /** Every task on the schedule, in the order the schedule lists them. */
    public function tasks(): HasMany
    {
        return $this->hasMany(JobTask::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Re-reads the job's estimated hours off its tasks.
     *
     * The chain is: the estimate prices the labour, a task carries the hours of
     * the lines it covers, and the job is what its tasks add up to. Always
     * recomputed rather than adjusted, so adding, editing or removing a task
     * all land on the same number and a second pass cannot double-count.
     */
    public function refreshEstimatedHours(): void
    {
        $hours = (float) $this->tasks()->sum('estimated_hours');

        // Null, not zero: zero would claim the work takes no time.
        $this->update(['estimated_hours' => $hours > 0 ? round($hours, 2) : null]);
    }

    /** @return HasMany<JobNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(JobNote::class)->latest();
    }

    /** @return HasMany<JobAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(JobAttachment::class)->latest();
    }

    /** @return HasMany<JobActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(JobActivity::class)->latest()->latest('id');
    }

    /** @return HasMany<JobStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(JobStatusChange::class)->latest()->latest('id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** GPS check-in/check-out — distinct from {@see timeEntries()}, which is
     *  logged work hours with an approval workflow; this is raw presence. */
    public function attendances(): HasMany
    {
        return $this->hasMany(JobAttendance::class, 'job_id');
    }

    /** One row per foreman assigned to this job — their own submit/approve
     *  progress, independent of every other foreman's. */
    public function foremanCompletions(): HasMany
    {
        return $this->hasMany(JobForemanCompletion::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->latest('updated_at');
    }

    /* ------------------------------------------------------------------ Scopes */

    /** Matches a job name, client, location or its foreman's name. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('client', 'like', "%{$term}%")
                ->orWhere('location', 'like', "%{$term}%")
                ->orWhereHas('foreman', fn (Builder $foreman) => $foreman->where('name', 'like', "%{$term}%"));
        });
    }

    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'name-asc' => $query->orderBy('name'),
            'start-asc' => $query->orderByRaw('start_date is null')->orderBy('start_date'),
            'start-desc' => $query->orderByRaw('start_date is null')->orderByDesc('start_date'),
            'budget-desc' => $query->orderByRaw('budget is null')->orderByDesc('budget'),
            'budget-asc' => $query->orderByRaw('budget is null')->orderBy('budget'),
            default => $query->latest()->latest('id'),
        };
    }

    /**
     * Jobs with no crew shift on the calendar yet.
     *
     * "Unassigned" is the absence of a schedule row, not a status: a job can sit at
     * `scheduled` because an estimate was signed off and still have nobody booked to
     * do it. Completed and archived work is excluded — there is nothing left to book.
     */
    public function scopeUnscheduled(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('status', '!=', 'completed')
            ->whereDoesntHave('crewShifts');
    }

    /** The inverse: jobs that already have at least one shift booked. */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->active()->whereHas('crewShifts');
    }

    /**
     * Ordering for the unassigned queue.
     *
     * Priority is stored as a word, so it cannot be ordered alphabetically —
     * "high" would sort before "low" and "medium" by accident rather than by rank.
     * `FIELD()` gives the real ranking, with a portable fallback for other drivers.
     */
    public function scopeSortedForScheduling(Builder $query, ?string $sort): Builder
    {
        $rank = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "field(priority, 'high', 'medium', 'low')",
            default => "case priority when 'high' then 1 when 'medium' then 2 else 3 end",
        };

        return match ($sort) {
            'priority-desc' => $query->orderByRaw($rank)->orderByDesc('id'),
            'priority-asc' => $query->orderByRaw("{$rank} desc")->orderByDesc('id'),
            'hours-desc' => $query->orderByRaw('estimated_hours is null')->orderByDesc('estimated_hours'),
            'hours-asc' => $query->orderByRaw('estimated_hours is null')->orderBy('estimated_hours'),
            'value-desc' => $query->orderByRaw('budget is null')->orderByDesc('budget'),
            'created-desc' => $query->latest('created_at')->orderByDesc('id'),
            'name-asc' => $query->orderBy('name'),
            // Newest work date first — a job with no start date yet sorts last
            // rather than first, so it doesn't outrank dated work.
            default => $query->orderByRaw('start_date is null')->orderByDesc('start_date')->orderByDesc('id'),
        };
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /* ---------------------------------------------------------------- Behaviour */

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Writes one activity row. Every mutating action funnels through here so the
     * timeline can never fall out of step with the data.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordActivity(string $type, string $description, array $meta = []): JobActivity
    {
        return $this->activities()->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** Statuses a job sits in before any real work has begun on it. */
    private const NOT_YET_STARTED = ['draft', 'planning', 'scheduled'];

    /**
     * Whether work has actually begun — everything past the planning
     * statuses, including `on-hold`/`delayed`/`completed`, since a job
     * cannot be put on hold or finished before it started. Mobile gates
     * every task action (complete, progress, checklist) on this: nothing
     * on a job's tasks should move before the job itself has.
     */
    public function hasStarted(): bool
    {
        return ! in_array($this->status, self::NOT_YET_STARTED, true);
    }

    /** Once a job is completed, nothing about it should change again. */
    public function isLocked(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** The crew says every task is done and it's ready for a supervisor's sign-off. */
    public function isReadyForReview(): bool
    {
        return $this->ready_for_review_at !== null;
    }

    /**
     * The crew's sign-off: every task is closed, but only a supervisor can
     * actually complete the job from here (`Api\V1\JobController::changeStatus`).
     * Deliberately does not touch `status` itself — see the model's own
     * `casts()` comment on why this column exists instead of an 8th status.
     */
    public function markReadyForReview(): void
    {
        // Direct assignment + save, not `update()` — `ready_for_review_at`
        // is deliberately not in `$fillable` (see the `casts()` comment),
        // and `update()` mass-assigns through `fill()`, which a non-fillable
        // attribute would silently not survive.
        $this->ready_for_review_at = now();
        $this->save();

        $this->recordActivity(
            'ready_for_review',
            'Marked ready for supervisor review — every task is complete.',
        );

        Notification::send(
            $this->notifiableSupervisors(),
            new JobReviewStatusChanged($this, JobReviewStatusChanged::READY_FOR_REVIEW),
        );
    }

    /**
     * Undoes a crew sign-off — a supervisor reopened a task while reviewing,
     * so the crew has to close it again before the job can complete.
     */
    public function clearReadyForReview(): void
    {
        if (! $this->isReadyForReview()) {
            return;
        }

        $this->ready_for_review_at = null;
        $this->save();

        $this->recordActivity(
            'ready_for_review_reverted',
            'Sent back to the crew — a task was reopened after review.',
        );

        Notification::send(
            $this->notifiableForemen(),
            new JobReviewStatusChanged($this, JobReviewStatusChanged::REVERTED),
        );
    }

    /**
     * The foreman ids actually assigned to this job's tasks — the set every
     * per-foreman completion check is scoped against. Falls back to the
     * job's own header `foreman_id`, same as {@see assignedForemen()}, for
     * jobs raised before tasks carried their own foreman.
     *
     * @return Collection<int, int>
     */
    public function assignedForemanIds(): Collection
    {
        $fromTasks = $this->tasks()->whereNotNull('foreman_id')->pluck('foreman_id')->unique()->values();

        return $fromTasks->isNotEmpty()
            ? $fromTasks
            : collect($this->foreman_id === null ? [] : [$this->foreman_id]);
    }

    /**
     * Open tasks belonging to one foreman — scoped to `foreman_id` when the
     * job's tasks actually carry one, the same convention
     * {@see assignedForemanIds()} uses; falls back to every task on the job
     * for one raised before tasks carried their own foreman, where the
     * header field names the only foreman there is.
     */
    public function myOpenTasksCount(int $foremanId): int
    {
        $hasPerTaskForemen = $this->tasks()->whereNotNull('foreman_id')->exists();

        return $hasPerTaskForemen
            ? $this->tasks()->where('foreman_id', $foremanId)->open()->count()
            : $this->tasks()->open()->count();
    }

    /** This foreman's own completion row, created empty the first time it's touched. */
    public function foremanCompletionFor(int $foremanId): JobForemanCompletion
    {
        return $this->foremanCompletions()->firstOrCreate(['foreman_id' => $foremanId]);
    }

    /**
     * Records this foreman's own start — independent of the job's single
     * shared `status`, and of every other foreman's own row. One foreman
     * starting (or finishing) their own work is never what tells another
     * foreman's own "Start Job" to stop showing.
     *
     * Idempotent: a foreman tapping Start a second time (a retried request,
     * or simply reopening the job) leaves the original timestamp alone.
     */
    public function markForemanStarted(int $foremanId): void
    {
        $completion = $this->foremanCompletionFor($foremanId);
        if ($completion->isStarted()) {
            return;
        }

        $completion->started_at = now();
        $completion->save();
    }

    /** Ids of foremen who have submitted their own tasks but are not yet approved. */
    public function readyForemanIds(): Collection
    {
        return $this->foremanCompletions()
            ->whereNotNull('ready_for_review_at')
            ->whereNull('approved_at')
            ->pluck('foreman_id');
    }

    /**
     * Whether every foreman actually assigned to this job has been approved
     * — the real gate for closing the job itself, as opposed to
     * {@see isReadyForReview()}, which is only a bridged, whole-job echo of
     * that for the handful of call sites (timer, notes, attachments) that
     * predate per-foreman tracking and only care about "is anyone still
     * mid-review at all."
     */
    public function isFullyApprovedByForemen(): bool
    {
        $ids = $this->assignedForemanIds();
        if ($ids->isEmpty()) {
            return $this->isReadyForReview();
        }

        return $this->foremanCompletions()
            ->whereIn('foreman_id', $ids)
            ->whereNotNull('approved_at')
            ->count() === $ids->count();
    }

    /**
     * Foremen currently waiting on a supervisor's review, `id` alongside
     * `name` so a caller can target one specifically — a supervisor
     * approving one foreman from their task list must never sweep up
     * whichever other foreman also happens to be ready at the same moment.
     *
     * @return list<array{id: int, name: string}>
     */
    public function readyForemen(): array
    {
        $ids = $this->readyForemanIds();
        if ($ids->isEmpty()) {
            return [];
        }

        return Foreman::whereKey($ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Foreman $f) => ['id' => $f->id, 'name' => $f->name])
            ->all();
    }

    /**
     * Assigned foremen who have not yet been approved, `id` alongside
     * `name` — for the "waiting on: ..." list the app shows a foreman or
     * supervisor once their own review has moved forward.
     *
     * @return list<array{id: int, name: string}>
     */
    public function pendingForemen(): array
    {
        $ids = $this->assignedForemanIds();
        if ($ids->isEmpty()) {
            return [];
        }

        $approvedIds = $this->foremanCompletions()
            ->whereIn('foreman_id', $ids)
            ->whereNotNull('approved_at')
            ->pluck('foreman_id');

        $pendingIds = $ids->diff($approvedIds);
        if ($pendingIds->isEmpty()) {
            return [];
        }

        return Foreman::whereKey($pendingIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Foreman $f) => ['id' => $f->id, 'name' => $f->name])
            ->all();
    }

    /**
     * One foreman's own sign-off: their own tasks are all closed, but only a
     * supervisor's approval of this row — not the whole job's — moves them
     * on. Does not touch `ready_for_review_at`/`status` directly; those stay
     * a bridged echo, only flipped once every foreman here is ready/approved
     * (see {@see JobController::changeStatus()}).
     */
    public function markForemanReadyForReview(int $foremanId): void
    {
        $completion = $this->foremanCompletionFor($foremanId);
        $completion->ready_for_review_at = now();
        $completion->approved_at = null;
        $completion->save();

        $this->recordActivity(
            'foreman_ready_for_review',
            'A foreman marked their own tasks ready for supervisor review.',
        );

        if (! $this->isReadyForReview() && $this->haveAllForemenSubmitted()) {
            // Every assigned foreman has now submitted their own portion —
            // bridge the legacy whole-job flag for the call sites that only
            // ever understood one shared review state (timer/notes/attachments).
            $this->markReadyForReview();
        }

        Notification::send(
            $this->notifiableSupervisors(),
            new JobReviewStatusChanged($this, JobReviewStatusChanged::READY_FOR_REVIEW),
        );
    }

    /**
     * Undoes one foreman's own sign-off — a supervisor reopened one of
     * *their* tasks while reviewing. Never touches another foreman's
     * already-approved row: their portion staying approved regardless of
     * this one being sent back is the whole point of tracking this
     * per-foreman rather than job-wide.
     */
    public function clearForemanReadyForReview(int $foremanId): void
    {
        $completion = $this->foremanCompletions()->where('foreman_id', $foremanId)->first();
        if ($completion === null
            || ($completion->ready_for_review_at === null && $completion->approved_at === null)) {
            return;
        }

        $completion->ready_for_review_at = null;
        $completion->approved_at = null;
        $completion->save();

        // The bridged whole-job flag only ever meant "everyone was ready" —
        // one foreman being sent back makes that no longer true.
        $this->clearReadyForReview();

        Notification::send(
            $completion->foreman?->user !== null ? collect([$completion->foreman->user]) : collect(),
            new JobReviewStatusChanged($this, JobReviewStatusChanged::REVERTED),
        );
    }

    /** Records this foreman's own portion as signed off by a supervisor. */
    public function approveForeman(int $foremanId): JobForemanCompletion
    {
        $completion = $this->foremanCompletionFor($foremanId);
        $completion->approved_at = now();
        $completion->save();

        return $completion;
    }

    /** Whether every foreman assigned to this job has at least submitted
     *  their own tasks for review (approved or still waiting either way) —
     *  what the legacy whole-job `ready_for_review_at` bridge is keyed on. */
    private function haveAllForemenSubmitted(): bool
    {
        $ids = $this->assignedForemanIds();
        if ($ids->isEmpty()) {
            return false;
        }

        return $this->foremanCompletions()
            ->whereIn('foreman_id', $ids)
            ->where(fn (Builder $q) => $q->whereNotNull('ready_for_review_at')->orWhereNotNull('approved_at'))
            ->count() === $ids->count();
    }

    /**
     * The actual `User` accounts behind {@see assignedSupervisors()} — that
     * method returns display-only name/initials arrays, not models a
     * notification can be sent to. Public: reused by
     * `App\Listeners\NotifyOfJobCompletion`/`NotifyOfJobStarted`, which live
     * outside this model.
     *
     * @return Collection<int, User>
     */
    public function notifiableSupervisors(): Collection
    {
        return $this->tasks->pluck('supervisor')->filter()->unique('id')
            ->map(fn (Foreman $supervisor) => $supervisor->user)
            ->filter()
            ->values();
    }

    /**
     * The actual `User` accounts behind {@see assignedForemen()} — same
     * reasoning as {@see notifiableSupervisors()}. Also public for the same
     * reason.
     *
     * @return Collection<int, User>
     */
    public function notifiableForemen(): Collection
    {
        $fromTasks = $this->tasks->pluck('foreman')->filter()->unique('id');
        $foremen = $fromTasks->isNotEmpty() ? $fromTasks : collect([$this->foreman])->filter();

        return $foremen
            ->map(fn (Foreman $foreman) => $foreman->user)
            ->filter()
            ->values();
    }

    /** Applies a status change, recording both the trail row and the activity. */
    public function changeStatus(string $status): void
    {
        $from = $this->status;

        if ($from === $status) {
            return;
        }

        $this->update(['status' => $status]);

        $this->statusChanges()->create([
            'user_id' => Auth::id(),
            'from_status' => $from,
            'to_status' => $status,
        ]);

        event(new JobStatusChanged($this, $from, $status));

        $this->recordActivity(
            'status_changed',
            "Status changed from {$from} to {$status}",
            ['from' => $from, 'to' => $status],
        );
    }

    /** Records the opening status row for a freshly created job. */
    public function recordInitialStatus(): void
    {
        $this->statusChanges()->create([
            'user_id' => Auth::id(),
            'from_status' => null,
            'to_status' => $this->status,
        ]);
    }
}
