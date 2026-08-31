<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single, immutable ledger row type. A user's balance is never stored —
 * it is always derived by summing `amount` over these rows for that user
 * (see `BreezeBucksLedger`), so the ledger can never drift from itself.
 */
class BreezeBucksTransaction extends Model
{
    public const TYPE_EARNED = 'earned';

    public const TYPE_REDEEMED = 'redeemed';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_BONUS = 'bonus';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPE_LABELS = [
        self::TYPE_EARNED => 'Earned',
        self::TYPE_REDEEMED => 'Redeemed',
        self::TYPE_ADJUSTMENT => 'Adjustment',
        self::TYPE_BONUS => 'Bonus',
        self::TYPE_REVERSAL => 'Reversal',
    ];

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_after',
        'source_type', 'source_id', 'description', 'metadata', 'created_by',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
