<?php

namespace App\Models;

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
