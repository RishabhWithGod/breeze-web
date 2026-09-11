<?php

namespace App\Models;

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
            ->orWhere('supervisor_id', $memberId));
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
    public static function workload(bool $closed): Collection
    {
        $held = function (string $column) use ($closed) {
            $query = static::query()
                ->whereHas('job')
                ->whereNotNull($column)
                ->when(
                    $closed,
                    fn (Builder $q) => $q->whereIn('status', self::CLOSED_STATUSES),
                    fn (Builder $q) => $q->whereNotIn('status', self::CLOSED_STATUSES),
                );

            // Supervising a task you are also running is one job of work.
            if ($column === 'supervisor_id') {
                $query->where(fn (Builder $q) => $q
                    ->whereNull('foreman_id')
                    ->orWhereColumn('supervisor_id', '!=', 'foreman_id'));
            }

            return $query->select([
                $column.' as member_id',
                'job_id',
                'estimated_hours',
            ]);
        };

        return DB::query()
            ->fromSub($held('foreman_id')->unionAll($held('supervisor_id')), 'held')
            ->groupBy('member_id')
            ->selectRaw(
                'member_id, count(*) as tasks, count(distinct job_id) as jobs, '.
                'coalesce(sum(estimated_hours), 0) as hours'
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
