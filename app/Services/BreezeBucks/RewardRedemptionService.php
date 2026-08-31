<?php

namespace App\Services\BreezeBucks;

use App\Models\BreezeBucksRedemption;
use App\Models\BreezeBucksTransaction;
use App\Models\RewardCatalogItem;
use App\Models\User;
use App\Notifications\BreezeBucksAwarded;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The redeem workflow end to end: validate → debit the ledger → decrement
 * stock → create the redemption record, all inside one database
 * transaction. `BreezeBucksLedger::record()` already locks the user's row
 * and rejects a debit that would go negative, so two concurrent redemption
 * requests can never both succeed against the same balance.
 */
class RewardRedemptionService
{
    public function __construct(private readonly BreezeBucksLedger $ledger) {}

    public function redeem(User $user, RewardCatalogItem $reward): BreezeBucksRedemption
    {
        return DB::transaction(function () use ($user, $reward) {
            $locked = RewardCatalogItem::where('id', $reward->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['reward' => 'That reward is no longer available.']);
            }

            if (! $locked->isInStock()) {
                throw ValidationException::withMessages(['reward' => 'That reward is out of stock.']);
            }

            $transaction = $this->ledger->record(
                $user,
                BreezeBucksTransaction::TYPE_REDEEMED,
                -$locked->points_required,
                "Redeemed: {$locked->name}",
                'reward_catalog_item',
                $locked->id,
            );

            if ($locked->stock !== null) {
                $locked->decrement('stock');
            }

            $redemption = BreezeBucksRedemption::create([
                'user_id' => $user->id,
                'reward_catalog_item_id' => $locked->id,
                'breeze_bucks_transaction_id' => $transaction->id,
                'points_spent' => $locked->points_required,
                'status' => BreezeBucksRedemption::STATUS_COMPLETED,
                'redemption_reference' => (string) Str::uuid(),
            ]);

            $user->notify(new BreezeBucksAwarded($transaction, redemption: $redemption));

            return $redemption;
        });
    }
}
