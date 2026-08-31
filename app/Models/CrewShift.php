<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'duration_hours' => 'decimal:2',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
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
