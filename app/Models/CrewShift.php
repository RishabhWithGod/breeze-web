<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ScheduleShiftChanged;

/**
 * One crew shift on one job, on one day.
 *
 * This is the unit the scheduling calendar draws. A multi-day job is several of
 * these, which is what lets a crew change mid-job without rewriting the job itself.
 */
class CrewShift extends Model
{
    /** Renamed from `job_schedules`, which now holds the job's own schedule. */
    protected $table = 'crew_shifts';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'job_id',
        'team_member_id',
        'created_by',
        'crew',
        'scheduled_date',
        'start_time',
        'duration_hours',
        'status',
        'notes',
        'changed_at',
    ];

    /** The fields that, once changed, make a booked shift read "Changed" until it is acknowledged. */
    private const SCHEDULING_FIELDS = ['scheduled_date', 'start_time', 'duration_hours', 'crew', 'team_member_id'];

    protected static function booted(): void
    {
        static::updating(function (CrewShift $shift): void {
            if ($shift->isDirty(self::SCHEDULING_FIELDS) && ! $shift->isDirty('changed_at')) {
                $shift->changed_at = now();
            }
        });

        static::updated(function (CrewShift $shift): void {
            if ($shift->wasChanged('changed_at')) {
                Notification::send($shift->affectedUsers(), new ScheduleShiftChanged($shift));
            }
        });

        static::deleting(function (CrewShift $shift): void {
            Notification::send(
                $shift->affectedUsers(),
                new ScheduleShiftChanged($shift, ScheduleShiftChanged::CANCELLED, $shift->scheduled_date?->format('D, M j').' at '.$shift->startLabel()),
            );
        });
    }

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'duration_hours' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** Only shifts on this manager's own jobs. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('job', fn (Builder $q) => $q->ownedBy($user));
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ Scopes */

    /** Shifts falling inside a calendar window, in the order the grid draws them. */
    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereBetween('scheduled_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->orderBy('id');
    }

    /** Whoever has acknowledged the shift. @return HasMany<CrewShiftAcknowledgement, $this> */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(CrewShiftAcknowledgement::class);
    }

    /**
     * The people a change to this shift matters to: whoever it is booked for, and whoever is
     * staffed on the job — minus the person who made the change.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function affectedUsers(): \Illuminate\Support\Collection
    {
        $job = $this->job()->with(['tasks.foreman', 'tasks.supervisor', 'tasks.assignments.member', 'foreman', 'assignments'])->first();
        $ids = collect([$this->teamMember?->user_id])
            ->merge($job?->assignments->whereNull('released_at')->pluck('user_id') ?? [])
            ->merge($job?->tasks->flatMap(fn ($task) => [$task->foreman?->user_id, $task->supervisor?->user_id]) ?? [])
            ->merge($job?->tasks->flatMap(fn ($task) => $task->assignments->map(fn ($a) => $a->member?->user_id)) ?? [])
            ->push($job?->foreman?->user_id)
            ->filter()
            ->reject(fn ($id) => $id === Auth::id())
            ->unique()
            ->values();

        return User::query()->whereKey($ids)->get();
    }

    /* ---------------------------------------------------------------- Behaviour */

    /** "8:00 AM" — the label the calendar block shows. */
    public function startLabel(): string
    {
        return Carbon::parse($this->start_time)->format('g:i A');
    }

    /** Where the shift ends, for the crew-availability read-out. */
    public function endLabel(): string
    {
        return Carbon::parse($this->start_time)
            ->addMinutes((int) round(((float) $this->duration_hours) * 60))
            ->format('g:i A');
    }
}
