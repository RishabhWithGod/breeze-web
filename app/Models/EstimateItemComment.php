<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note left on one material line, in conversation order — the
 *  per-material analogue of {@see JobTaskComment}. */
class EstimateItemComment extends Model
{
    protected $fillable = ['estimate_item_id', 'user_id', 'body'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'estimate_item_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
