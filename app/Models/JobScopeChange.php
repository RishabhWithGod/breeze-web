<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One remembered change to a job's scope — see the `job_scope_changes` migration. */
class JobScopeChange extends Model
{
    public const UPDATED_AT = null;

    public const KIND_ESTIMATE = 'estimate';

    public const KIND_CHANGE_ORDER = 'change_order';

    public const KIND_COST_ENTRY = 'cost_entry';

    public const KIND_INVOICE = 'invoice';

    public const KIND_HOURS = 'hours';

    protected $fillable = [
        'job_id', 'estimate_id', 'version', 'kind', 'source', 'user_id', 'summary',
        'item_changes', 'totals_before', 'totals_after', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'item_changes' => 'array',
            'totals_before' => 'array',
            'totals_after' => 'array',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
