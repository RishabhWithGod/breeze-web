<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the crew actually used or added on site. With an `estimate_item_id` it
 * is the actual quantity against that planned line; without one it is a
 * material added in the field. Never an approved change order — those are
 * raised separately and priced by the office.
 */
class JobFieldMaterial extends Model
{
    protected $fillable = [
        'job_id', 'job_task_id', 'estimate_item_id', 'price_book_item_id', 'client_key',
        'description', 'unit', 'actual_quantity', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return ['actual_quantity' => 'decimal:4'];
    }

    public function isAdded(): bool
    {
        return $this->estimate_item_id === null;
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<JobTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(JobTask::class, 'job_task_id');
    }

    /** @return BelongsTo<EstimateItem, $this> */
    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
