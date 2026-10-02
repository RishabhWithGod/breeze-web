<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A field edit that clashed with an office edit and was sent up for a manager to decide. */
class SyncConflict extends Model
{
    public const KEEP_OFFICE = 'keep_office';

    public const APPLY_FIELD = 'apply_field';

    protected $fillable = [
        'job_id', 'user_id', 'entity_type', 'entity_id', 'title', 'fields',
        'status', 'resolution', 'resolved_by', 'resolved_at', 'note',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'resolved_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
