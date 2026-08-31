<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BreezeBucksRedemption extends Model
{
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'user_id', 'reward_catalog_item_id', 'breeze_bucks_transaction_id',
        'points_spent', 'status', 'redemption_reference',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RewardCatalogItem, $this> */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(RewardCatalogItem::class, 'reward_catalog_item_id');
    }

    /** @return BelongsTo<BreezeBucksTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BreezeBucksTransaction::class, 'breeze_bucks_transaction_id');
    }
}
