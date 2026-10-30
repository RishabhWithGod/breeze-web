<?php

namespace App\Models;

use App\Services\JobScope\JobScopeLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manager's own, directly-saved total hours for one person on one job —
 * see `JobCostSummary::journeymanHours()` for how this overrides whatever
 * that person's own time entries add up to, once it exists.
 */
class JobJourneymanHours extends Model
{
    protected $table = 'job_journeyman_hours';

    protected $fillable = [
        'job_id',
        'user_id',
        'hours',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $row): void {
            $job = Job::find($row->job_id);
            if ($job === null || ! ($row->wasRecentlyCreated || $row->wasChanged('hours'))) {
                return;
            }

            $name = User::whereKey($row->user_id)->value('name') ?? 'a team member';
            app(JobScopeLog::class)->record($job, JobScopeChange::KIND_HOURS, "Hours set for {$name}");
        });
    }

    protected function casts(): array
    {
        return [
            'hours' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
