<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A block of time worked, against a job and (optionally) one of its tasks.
 *
 * Lifecycle: draft → submitted → approved (locked against direct edits from
 * here on) or rejected (back to the employee, editable again). Correcting an
 * approved entry moves it to `locked` — superseded, kept only for audit, no
 * longer counted in any hour/cost total — and creates a fresh `draft` row with
 * `corrects_id` pointing at it, which goes through submit/approve on its own.
 * The approved history an approver actually saw is never rewritten.
 */
class TimeEntry extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LOCKED = 'locked';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_LOCKED,
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TIMER = 'timer';

    protected $fillable = [
        'job_id',
        'job_task_id',
        'user_id',
        'team_member_id',
        'date',
        'start_time',
        'end_time',
        'break_minutes',
        'hours',
        'regular_hours',
        'overtime_hours',
        'task_label',
        'description',
        'billable',
        'billable_rate',
        'cost_rate',
        'labor_cost',
        'billable_amount',
        'source',
        'status',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'approved_by',
        'rejected_by',
        'rejection_reason',
        'corrects_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'break_minutes' => 'integer',
            'hours' => 'decimal:2',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'billable' => 'boolean',
            'billable_rate' => 'decimal:2',
            'cost_rate' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'billable_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<JobTask, $this> */
    public function jobTask(): BelongsTo
    {
        return $this->belongsTo(JobTask::class);
    }

    /** Who is signed in and owns this entry. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whose staffing record the hours count against. */
    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** The locked entry this row corrects, when it is a correction. */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_id');
    }

    /** @return HasMany<self, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'corrects_id');
    }

    /** @return HasMany<TimeEntryActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TimeEntryActivity::class)->latest()->latest('id');
    }

    /** @return HasMany<TimeEntryStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(TimeEntryStatusChange::class)->latest()->latest('id');
    }

    /* ------------------------------------------------------------------ Scopes */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('task_label', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('job', fn (Builder $job) => $job->where('name', 'like', "%{$term}%"))
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$term}%"));
        });
    }

    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'date-asc' => $query->orderBy('date')->orderBy('id'),
            'hours-desc' => $query->orderByDesc('hours'),
            'hours-asc' => $query->orderBy('hours'),
            default => $query->orderByDesc('date')->orderByDesc('id'),
        };
    }

    /* ---------------------------------------------------------------- Behaviour */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_LOCKED], true);
    }

    public function isCorrection(): bool
    {
        return $this->corrects_id !== null;
    }

    /**
     * Writes one activity row. Every mutating action funnels through here so the
     * timeline can never fall out of step with the data.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordActivity(string $type, string $description, array $meta = []): TimeEntryActivity
    {
        return $this->activities()->create([
            'user_id' => Auth::id(),
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
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

        $this->recordActivity(
            'status_changed',
            "Status changed from {$from} to {$status}",
            ['from' => $from, 'to' => $status],
        );
    }

    /** Records the opening status row for a freshly created entry. */
    public function recordInitialStatus(): void
    {
        $this->statusChanges()->create([
            'user_id' => Auth::id(),
            'from_status' => null,
            'to_status' => $this->status,
        ]);
    }
}
