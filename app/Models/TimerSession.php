<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The one active/paused timer a user has running.
 *
 * Elapsed time is never trusted from the client: it is always
 * `accumulated_seconds` plus (if `status` is `running`) the seconds since
 * `started_at`. See `TimerService::elapsedSeconds()`.
 */
class TimerSession extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'user_id',
        'job_id',
        'job_task_id',
        'team_member_id',
        'task_label',
        'description',
        'started_at',
        'paused_at',
        'accumulated_seconds',
        'status',
        'billable',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'accumulated_seconds' => 'integer',
            'billable' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function jobTask(): BelongsTo
    {
        return $this->belongsTo(JobTask::class);
    }

    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }
}
