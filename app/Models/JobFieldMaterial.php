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
    public const MATERIAL = 'material';

    public const LABOR = 'labor';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'job_id', 'job_task_id', 'kind', 'estimate_item_id', 'price_book_item_id', 'client_key',
        'description', 'unit', 'actual_quantity', 'unit_price', 'total', 'status',
        'added_estimate_item_id', 'approved_by', 'approved_at', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'actual_quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function isLabor(): bool
    {
        return $this->kind === self::LABOR;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
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
