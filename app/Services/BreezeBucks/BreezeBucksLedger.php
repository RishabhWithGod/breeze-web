<?php

namespace App\Services\BreezeBucks;

use App\Models\BreezeBucksTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sole writer of `breeze_bucks_transactions` and the sole source of a
 * user's balance — always `SUM(amount)` over their rows, never a stored
 * counter, so there is nothing that can drift from the ledger itself.
 */
class BreezeBucksLedger
{
    public function balanceFor(User $user): int
    {
        return (int) BreezeBucksTransaction::where('user_id', $user->id)->sum('amount');
    }

    public function lifetimeEarnedFor(User $user): int
    {
        return (int) BreezeBucksTransaction::where('user_id', $user->id)
            ->whereIn('type', [BreezeBucksTransaction::TYPE_EARNED, BreezeBucksTransaction::TYPE_BONUS])
            ->sum('amount');
    }

    public function lifetimeRedeemedFor(User $user): int
    {
        return (int) abs(BreezeBucksTransaction::where('user_id', $user->id)
            ->where('type', BreezeBucksTransaction::TYPE_REDEEMED)
            ->sum('amount'));
    }

    /**
     * Locks the user's row for the duration of the write so two concurrent
     * debits (e.g. a double-clicked redemption) can never both read the same
     * pre-debit balance and both succeed.
     *
     * @param  int  $signedAmount  Positive to credit, negative to debit.
     *
     * @throws ValidationException if a debit would take the balance below zero.
     */
    public function record(
        User $user,
        string $type,
        int $signedAmount,
        string $description,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $createdBy = null,
        ?array $metadata = null,
    ): BreezeBucksTransaction {
        return DB::transaction(function () use ($user, $type, $signedAmount, $description, $sourceType, $sourceId, $createdBy, $metadata) {
            User::where('id', $user->id)->lockForUpdate()->first();

            $balanceBefore = $this->balanceFor($user);
            $balanceAfter = $balanceBefore + $signedAmount;

            if ($balanceAfter < 0) {
                $needed = abs($signedAmount);

                throw ValidationException::withMessages([
                    'points' => "Insufficient Breeze Bucks. You need {$needed} BB but only have {$balanceBefore} BB.",
                ]);
            }

            return BreezeBucksTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $signedAmount,
                'balance_after' => $balanceAfter,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'description' => $description,
                'metadata' => $metadata,
                'created_by' => $createdBy,
            ]);
        });
    }
}
