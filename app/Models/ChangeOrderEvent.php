<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a change order's history. */
class ChangeOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['change_order_id', 'user_id', 'type', 'note', 'sell_total'];

    protected function casts(): array
    {
        return ['sell_total' => 'decimal:2', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
