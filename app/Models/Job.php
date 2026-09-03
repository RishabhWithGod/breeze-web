<?php

namespace App\Models;

use App\Events\JobStatusChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'foreman_id',
        'project_id',
        'ai_result_id',
        'name',
        'client',
        'location',
        'latitude',
        'longitude',
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
            'budget' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
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
