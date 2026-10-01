<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time an estimate was sent for approval, and what it looked like then. */
class EstimateRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['estimate_id', 'version', 'changes', 'user_id', 'total', 'item_count'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'total' => 'decimal:2', 'item_count' => 'integer'];
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
