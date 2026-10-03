<?php

namespace App\Models;

use App\Services\Scheduling\JobCrewProjectSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One piece of work on a schedule.
 *
 * Status is the task's own state; whether it is *late* is derived from its dates and
 * never stored, so a task cannot claim to be on time while its end date is in the
 * past. `delayed` as a status means somebody recorded a delay with a reason — that
 * is a decision, not a calculation, and the two are deliberately separate.
 */
class JobTask extends Model
{
    protected static function booted(): void
    {
        // A task being created or handed to someone is what makes the job's
        // crew real, so the client and project pick it up if they had none.
        // Never allowed to fail the save it rides on.
        // The two columns are the first runner and first overseer; whoever writes them
        // (the old single-person forms, the mobile app, the job factory) keeps the full
        // crew list in step. Never removes anyone — {@see assignCrew()} owns that.
        static::saved(function (self $task): void {
            if ($task->wasRecentlyCreated || $task->wasChanged(['foreman_id', 'supervisor_id'])) {
                $task->keepPrimariesOnCrew();
            }
        });

        static::saved(function (self $task): void {
            if ($task->job_id === null || ! ($task->wasRecentlyCreated || $task->wasChanged(['foreman_id', 'supervisor_id']))) {
                return;
            }

            try {
                $job = Job::find($task->job_id);

                if ($job !== null) {
                    app(JobCrewProjectSync::class)->sync($job);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // The last task off a job takes its schedule with it, and the job is
        // unassigned again — the calendar draws from tasks, so nothing is left
        // to draw. While any task remains the schedule stays.
        static::deleted(function (self $task): void {
            if ($task->job_id === null || static::where('job_id', $task->job_id)->exists()) {
                return;
            }

            JobSchedule::where('job_id', $task->job_id)->delete();
            CrewShift::where('job_id', $task->job_id)->delete();
        });
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DELAYED = 'delayed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_READY,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_DELAYED,
    ];

    /** Statuses that take a task out of the running total. */
    public const CLOSED_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** Roles a person can hold on a task. */
    public const ROLES = [
        'estimator',
        'project-manager',
        'foreman',
        'electrician',
        'technician',
        'inspector',
        'reviewer',
    ];

    /** Work categories, used to colour the calendar and group the timeline. */
    public const CATEGORIES = [
        'survey',
        'rough-in',
        'installation',
        'termination',
        'testing',
        'inspection',
        'commissioning',
        'handover',
    ];

    protected $fillable = [
        'job_schedule_id',
        'job_id',
        'foreman_id',
        'supervisor_id',
        'created_by',
        'title',
        'description',
        'status',
        'priority',
        'category',
        'estimated_hours',
        'actual_hours',
        'starts_on',
        'ends_on',
        'baseline_ends_on',
        'completion_pct',
        'position',
        'is_milestone',
        'notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'baseline_ends_on' => 'date',
            'estimated_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'completion_pct' => 'integer',
            'position' => 'integer',
            'is_milestone' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<JobSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(JobSchedule::class, 'job_schedule_id');
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** Only tasks on jobs of the company this person belongs to. */
    public function scopeInCompanyOf(Builder $query, User $user): Builder
    {
        return $query->whereHas('job', fn (Builder $q) => $q->inCompanyOf($user));
    }

    /** Only tasks on this manager's own jobs. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('job', fn (Builder $q) => $q->ownedBy($user));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<JobTaskAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(JobTaskAssignment::class)->orderBy('role');
    }

    /**
     * The estimate lines this task is the work for.
     *
     * @return HasMany<EstimateItem, $this>
     */
    public function estimateItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('position');
    }

    /**
     * Who is running it. One person: a task with two people in charge has
     * nobody in charge.
     *
     * @return BelongsTo<Foreman, $this>
     */
    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Foreman::class);
    }

    /**
     * Who is over this task.
     *
     * A supervisor from the same crew as the foreman running it — see
     * `Job::team()`. Optional: plenty of work needs someone running it and
     * nobody above them.
     *
     * @return BelongsTo<Foreman, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Foreman::class, 'supervisor_id');
    }

    /** The people on this task. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TeamMember::class, 'job_task_assignments')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Edges pointing out of this task: what it waits on. */
    public function dependencies(): HasMany
    {
        return $this->hasMany(JobTaskDependency::class);
    }

    /** Edges pointing in: the tasks waiting on this one. */
    public function dependents(): HasMany
    {
        return $this->hasMany(JobTaskDependency::class, 'depends_on_id');
    }

    /** The tasks this one waits on. */
    public function predecessors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'job_task_dependencies', 'job_task_id', 'depends_on_id')
            ->withPivot(['type', 'lag_days']);
    }

    /** The tasks that wait on this one. */
    public function successors(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'job_task_dependencies', 'depends_on_id', 'job_task_id')
            ->withPivot(['type', 'lag_days']);
    }

    /** @return HasMany<JobTaskAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(JobTaskAttachment::class)->latest('id');
    }

    /** @return HasMany<JobTaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(JobTaskComment::class)->oldest('id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'job_task_id');
    }

    /* ------------------------------------------------------------- Task crew */

    public const SLOT_RUNNER = 'runner';

    public const SLOT_OVERSEER = 'overseer';

    /**
     * Everyone this task is given to, in either slot — `pivot->slot` says which.
     *
     * @return BelongsToMany<Foreman, $this>
     */
    public function crew(): BelongsToMany
    {
        return $this->belongsToMany(Foreman::class, 'job_task_foremen')->withPivot('slot')->withTimestamps();
    }

    /** @return Collection<int, int> Journeymen running the work. */
    public function runnerIds(): Collection
    {
        return $this->crewIds(self::SLOT_RUNNER, $this->foreman_id);
    }

    /** @return Collection<int, int> Foremen over it. */
    public function overseerIds(): Collection
    {
        return $this->crewIds(self::SLOT_OVERSEER, $this->supervisor_id);
    }

    /** @return Collection<int, int> */
    private function crewIds(string $slot, ?int $primary): Collection
    {
        // A list that already loaded `crew` is answered from it, so a page of tasks
        // does not ask the database once per task.
        $listed = $this->relationLoaded('crew')
            ? $this->crew->filter(fn (Foreman $person) => $person->pivot->slot === $slot)->pluck('id')
            : DB::table('job_task_foremen')->where('job_task_id', $this->id)->where('slot', $slot)->orderBy('id')->pluck('foreman_id');

        return $listed
            ->when($primary !== null, fn (Collection $ids) => $ids->prepend($primary))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    public function isRunBy(int $foremanId): bool
    {
        return $this->runnerIds()->contains($foremanId);
    }

    public function isOverseenBy(int $foremanId): bool
    {
        return $this->overseerIds()->contains($foremanId);
    }

    public function isHeldBy(int $foremanId): bool
    {
        return $this->isRunBy($foremanId) || $this->isOverseenBy($foremanId);
    }

    /**
     * Gives the task to exactly these people: journeymen to run it, foremen over it.
     *
     * The first of each becomes the task's `foreman_id` / `supervisor_id`, which
     * is what the rest of the app has always read. The crew list is written
     * first so anything reacting to the save already sees all of them.
     *
     * @param  array<int, int|string>  $runnerIds
     * @param  array<int, int|string>  $overseerIds
     */
    public function assignCrew(array $runnerIds, array $overseerIds): void
    {
        $runners = array_values(array_unique(array_map('intval', $runnerIds)));
        $overseers = array_values(array_unique(array_map('intval', $overseerIds)));
        $now = now();

        DB::transaction(function () use ($runners, $overseers, $now) {
            DB::table('job_task_foremen')->where('job_task_id', $this->id)->delete();

            $rows = [];
            foreach ([self::SLOT_RUNNER => $runners, self::SLOT_OVERSEER => $overseers] as $slot => $ids) {
                foreach ($ids as $id) {
                    $rows[] = ['job_task_id' => $this->id, 'foreman_id' => $id, 'slot' => $slot, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($rows !== []) {
                DB::table('job_task_foremen')->insert($rows);
            }

            $this->foreman_id = $runners[0] ?? null;
            $this->supervisor_id = $overseers[0] ?? null;
            $this->save();
        });
    }

    /**
     * Sends everyone who runs this task back to review — a task leaving
     * `completed` undoes each runner's own sign-off. Falls back to the
     * whole-job flag for a task with nobody running it.
     */
    public function clearRunnersReview(): void
    {
        $runners = $this->runnerIds();

        if ($runners->isEmpty()) {
            $this->job?->clearReadyForReview();

            return;
        }

        $runners->each(fn (int $id) => $this->job?->clearForemanReadyForReview($id));
    }

    /**
     * For writers that still name one person per slot (the mobile app): swaps the
     * previous first journeyman / foreman for the one now on the columns and keeps
     * everyone else the task was given to.
     */
    public function replacePrimaries(?int $oldRunner, ?int $oldOverseer): void
    {
        $swap = fn (Collection $ids, ?int $old, ?int $new) => $ids
            ->reject(fn (int $id) => $id === $old)
            ->prepend($new)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assignCrew(
            $swap($this->runnerIds(), $oldRunner, $this->foreman_id),
            $swap($this->overseerIds(), $oldOverseer, $this->supervisor_id),
        );
    }

    /** Makes sure the two primary columns are on the crew list; never removes anyone. */
    public function keepPrimariesOnCrew(): void
    {
        $now = now();

        foreach ([self::SLOT_RUNNER => $this->foreman_id, self::SLOT_OVERSEER => $this->supervisor_id] as $slot => $id) {
            if ($id !== null) {
                DB::table('job_task_foremen')->insertOrIgnore([
                    'job_task_id' => $this->id, 'foreman_id' => $id, 'slot' => $slot, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /* ------------------------------------------------------------------ Scopes */

    /**
     * Tasks this person is on, running one or overseeing it.
     *
     * A task names two people and both are carrying it: the foreman doing the
     * work and the supervisor answerable for it. Counting only the foreman is
     * what left a supervisor's register row reading nought open tasks while
     * they had a dozen.
     */
    public function scopeHeldBy(Builder $query, int $memberId): Builder
    {
        return $query->where(fn (Builder $held) => $held
            ->where('foreman_id', $memberId)
            ->orWhere('supervisor_id', $memberId)
            ->orWhereExists($this->onCrew($memberId)));
    }

    /**
     * Tasks held by any of these people — what a foreman's whole crew is carrying.
     *
     * @param  iterable<int, int>  $memberIds
     */
    public function scopeHeldByAny(Builder $query, iterable $memberIds): Builder
    {
        $ids = collect($memberIds)->filter()->unique()->values()->all();

        return $query->where(fn (Builder $held) => $held
            ->whereIn('foreman_id', $ids)
            ->orWhereIn('supervisor_id', $ids)
            ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('job_task_foremen')
                ->whereColumn('job_task_foremen.job_task_id', 'job_tasks.id')
                ->whereIn('job_task_foremen.foreman_id', $ids)));
    }

    /** Tasks this person is one of the journeymen running. */
    public function scopeRunBy(Builder $query, int $memberId): Builder
    {
        return $query->where(fn (Builder $held) => $held
            ->where('foreman_id', $memberId)
            ->orWhereExists($this->onCrew($memberId, self::SLOT_RUNNER)));
    }

    /** Tasks this person is one of the foremen over. */
    public function scopeOverseenBy(Builder $query, int $memberId): Builder
    {
        return $query->where(fn (Builder $held) => $held
            ->where('supervisor_id', $memberId)
            ->orWhereExists($this->onCrew($memberId, self::SLOT_OVERSEER)));
    }

    private function onCrew(int $memberId, ?string $slot = null): \Closure
    {
        return fn ($sub) => $sub->selectRaw('1')
            ->from('job_task_foremen')
            ->whereColumn('job_task_foremen.job_task_id', 'job_tasks.id')
            ->where('job_task_foremen.foreman_id', $memberId)
            ->when($slot !== null, fn ($q) => $q->where('job_task_foremen.slot', $slot));
    }

    /**
     * What every member of the crew register is carrying, in one query.
     *
     * A task counts once for its foreman and once for its supervisor — except
     * when they are the same person, who is carrying one task, not two. That is
     * the whole reason for the union: `group by foreman_id` cannot express "or
     * the other column", and a second query added on top would double-count the
     * jobs the two share.
     *
     * @return Collection<int, object>
     */
    public static function workload(bool $closed, ?\Closure $constrain = null): Collection
    {
        $tasks = static::query()
            ->whereHas('job')
            // Narrowed to whose work it is, when asked (a manager's own jobs, or a company's).
            ->when($constrain, fn (Builder $q) => $constrain($q))
            ->when(
                $closed,
                fn (Builder $q) => $q->whereIn('status', self::CLOSED_STATUSES),
                fn (Builder $q) => $q->whereNotIn('status', self::CLOSED_STATUSES),
            )
            ->select(['job_tasks.id as task_id', 'job_tasks.job_id', 'job_tasks.estimated_hours']);

        // One row per person per task — someone both running and overseeing a
        // task is carrying one task, not two.
        $people = DB::table('job_task_foremen')->select(['job_task_id', 'foreman_id'])->distinct();

        return DB::query()
            ->fromSub($tasks, 't')
            ->joinSub($people, 'm', 'm.job_task_id', '=', 't.task_id')
            ->groupBy('m.foreman_id')
            ->selectRaw(
                'm.foreman_id as member_id, count(*) as tasks, count(distinct t.job_id) as jobs, '.
                'coalesce(sum(t.estimated_hours), 0) as hours'
            )
            ->get()
            ->keyBy('member_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Tasks running late.
     *
     * Either recorded as delayed, or still open with an end date already gone. The
     * second half is what stops a forgotten task from looking healthy.
     */
    public function scopeLate(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('status', self::STATUS_DELAYED)
                ->orWhere(function (Builder $overdue) {
                    $overdue->whereNotIn('status', self::CLOSED_STATUSES)
                        ->whereNotNull('ends_on')
                        ->whereDate('ends_on', '<', Carbon::today());
                });
        });
    }

    /* ---------------------------------------------------------------- Behaviour */

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /** Past its end date and not finished — computed, never stored. */
    public function isOverdue(): bool
    {
        return ! $this->isClosed()
            && $this->ends_on !== null
            && $this->ends_on->lt(Carbon::today());
    }

    /** Days late against the end date; zero when not late. */
    public function daysLate(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->ends_on->diffInDays(Carbon::today());
    }

    /** Days pushed out since the schedule was baselined. */
    public function slippedDays(): int
    {
        if ($this->baseline_ends_on === null || $this->ends_on === null) {
            return 0;
        }

        return (int) $this->baseline_ends_on->diffInDays($this->ends_on, false);
    }

    /**
     * Whether every predecessor's constraint is satisfied.
     *
     * The three dependency types answer three different questions, so each is
     * checked on its own terms rather than all collapsed to "is it finished".
     */
    public function predecessorsSatisfied(): bool
    {
        foreach ($this->predecessors as $predecessor) {
            $type = $predecessor->pivot->type ?? JobTaskDependency::FINISH_TO_START;

            $satisfied = match ($type) {
                JobTaskDependency::START_TO_START => $predecessor->hasStarted(),
                JobTaskDependency::FINISH_TO_FINISH => $predecessor->isComplete(),
                default => $predecessor->isComplete(),
            };

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    public function hasStarted(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_DELAYED,
        ], true) || $this->completion_pct > 0;
    }

    /** Duration in working days, from the schedule's own calendar. */
    public function durationInWorkingDays(): int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return $this->is_milestone ? 0 : 1;
        }

        $schedule = $this->relationLoaded('schedule') ? $this->schedule : $this->schedule()->first();

        return $schedule?->countWorkingDays($this->starts_on, $this->ends_on) ?? 1;
    }
}
