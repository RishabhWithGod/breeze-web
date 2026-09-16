<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One foreman's own progress on one job — see the migration's own comment
 * for why this exists as its own row instead of a column on `work_jobs`.
 */
class JobForemanCompletion extends Model
{
    protected $fillable = [
        'job_id',
        'foreman_id',
        'started_at',
        'ready_for_review_at',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ready_for_review_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function foreman(): BelongsTo
    {
        return $this->belongsTo(Foreman::class);
    }

    public function isStarted(): bool
    {
        return $this->started_at !== null;
    }

    public function isReadyForReview(): bool
    {
        return $this->ready_for_review_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }
}
