<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The schedule a job runs to.
 *
 * Distinct from `CrewShift`, which is who is on site on a given day; this is the
 * plan those shifts serve — the window, the working week, the milestones and the
 * progress against them.
 *
 * The working week lives on the row rather than in config because it is negotiated
 * per job: a hospital retrofit works nights, an office fit-out does not, and a
 * duration in days is meaningless until you know which days count.
 */
class JobSchedule extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_ON_HOLD = 'on-hold';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD,
        self::STATUS_COMPLETED,
    ];

    /** Monday to Friday — the default working week. */
    public const DEFAULT_WORKING_DAYS = [1, 2, 3, 4, 5];

    protected $fillable = [
        'job_id',
        'created_by',
        'starts_on',
        'ends_on',
        'working_days',
        'work_start_time',
        'work_end_time',
        'break_minutes',
        'timezone',
        'holidays',
        'status',
        'progress_pct',
        'notes',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'working_days' => 'array',
            'holidays' => 'array',
            'break_minutes' => 'integer',
            'progress_pct' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Tasks in the order the schedule lists them. */
    public function tasks(): HasMany
    {
        return $this->hasMany(JobTask::class)->orderBy('position')->orderBy('id');
    }

    /** Dated commitments — tasks with no duration. */
    public function milestones(): HasMany
    {
        return $this->hasMany(JobTask::class)
            ->where('is_milestone', true)
            ->orderByRaw('ends_on is null')
            ->orderBy('ends_on');
    }

    /* ---------------------------------------------------------------- Behaviour */

    /** ISO day numbers the crew works, defaulted rather than left empty. */
    public function workingDays(): array
    {
        $days = array_values(array_filter(
            array_map('intval', $this->working_days ?? []),
            fn (int $day) => $day >= 1 && $day <= 7,
        ));

        return $days === [] ? self::DEFAULT_WORKING_DAYS : $days;
    }

    /** Non-working dates, as `Y-m-d` strings for cheap lookup. */
    public function holidayDates(): array
    {
        return array_values(array_filter(array_map(
            function ($value) {
                try {
                    return Carbon::parse((string) $value)->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            },
            $this->holidays ?? [],
        )));
    }

    /**
     * True when work happens on this date.
     *
     * Both halves matter: a Saturday is not a working day, and neither is a Monday
     * the site is shut. Duration and delay both count against this.
     */
    public function isWorkingDay(Carbon $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->workingDays(), true)
            && ! in_array($date->toDateString(), $this->holidayDates(), true);
    }

    /** Paid hours in one working day, after the unpaid break. */
    public function hoursPerDay(): float
    {
        $start = Carbon::parse($this->work_start_time);
        $end = Carbon::parse($this->work_end_time);

        if ($end->lte($start)) {
            return 0.0;
        }

        return round(max(0, $start->diffInMinutes($end) - $this->break_minutes) / 60, 2);
    }

    /**
     * Working days between the schedule's own dates, inclusive.
     *
     * Zero when either end is missing — an open-ended schedule has no duration, and
     * reporting one would be an invention.
     */
    public function durationInWorkingDays(): int
    {
        if ($this->starts_on === null || $this->ends_on === null) {
            return 0;
        }

        return $this->countWorkingDays($this->starts_on, $this->ends_on);
    }

    /** Working days in an arbitrary window, inclusive of both ends. */
    public function countWorkingDays(Carbon $from, Carbon $to): int
    {
        if ($to->lt($from)) {
            return 0;
        }

        $days = 0;

        for ($date = $from->copy()->startOfDay(); $date->lte($to); $date->addDay()) {
            if ($this->isWorkingDay($date)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * The next working day on or after a date.
     *
     * Used whenever a dependency pushes a task forward: landing it on a Sunday
     * would be a date nobody works to.
     */
    public function nextWorkingDay(Carbon $date): Carbon
    {
        $candidate = $date->copy()->startOfDay();

        // Bounded so a schedule with no working days cannot spin.
        for ($attempt = 0; $attempt < 366; $attempt++) {
            if ($this->isWorkingDay($candidate)) {
                return $candidate;
            }

            $candidate->addDay();
        }

        return $date->copy()->startOfDay();
    }

    /** Adds working days to a date, skipping weekends and holidays. */
    public function addWorkingDays(Carbon $date, int $days): Carbon
    {
        $candidate = $this->nextWorkingDay($date);

        for ($added = 0; $added < $days; $added++) {
            $candidate->addDay();
            $candidate = $this->nextWorkingDay($candidate);
        }

        return $candidate;
    }

    /** Holidays inside the schedule's window, for the calendar to shade. */
    public function holidaysInWindow(): Collection
    {
        return collect($this->holidayDates())
            ->filter(function (string $date) {
                $day = Carbon::parse($date);

                return ($this->starts_on === null || $day->gte($this->starts_on))
                    && ($this->ends_on === null || $day->lte($this->ends_on));
            })
            ->values();
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
