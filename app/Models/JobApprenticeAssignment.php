<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One apprentice, on one job, under one journeyman — set by a foreman from
 * the Job Detail screen. See the migration's own doc comment for why this
 * is its own table rather than another `job_tasks` column.
 */
class JobApprenticeAssignment extends Model
{
    protected $fillable = [
        'job_id',
        'journeyman_id',
        'apprentice_id',
        'assigned_by',
    ];

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<Foreman, $this> */
    public function journeyman(): BelongsTo
    {
        return $this->belongsTo(Foreman::class, 'journeyman_id');
    }

    /** @return BelongsTo<Foreman, $this> */
    public function apprentice(): BelongsTo
    {
        return $this->belongsTo(Foreman::class, 'apprentice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
