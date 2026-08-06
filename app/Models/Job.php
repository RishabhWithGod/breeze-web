<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

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

    public const TYPES = ['residential', 'commercial', 'industrial'];

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
        'foreman_id',
        'project_id',
        'ai_result_id',
        'name',
        'client',
        'location',
        'description',
        'job_type',
        'status',
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
            'budget' => 'decimal:2',
            'create_estimate' => 'boolean',
            'assign_team' => 'boolean',
            'notify_client' => 'boolean',
            'archived_at' => 'datetime',
            'symbol_counts' => 'array',
            'boq' => 'array',
            'metadata' => 'array',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Foreman, $this> */
    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Foreman::class);
    }

    /** The takeoff this job was created from, when it came from one. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
