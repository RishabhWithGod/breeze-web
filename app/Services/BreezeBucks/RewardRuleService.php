<?php

namespace App\Services\BreezeBucks;

use App\Models\BreezeBucksTransaction;
use App\Models\RewardRule;
use App\Models\User;
use App\Notifications\BreezeBucksAwarded;

/**
 * The only place a real business event turns into Breeze Bucks. A listener
 * calls `award()` with an event type; the point value and whether the event
 * awards anything at all come entirely from the `reward_rules` row — never
 * hard-coded here.
 */
class RewardRuleService
{
    public function __construct(private readonly BreezeBucksLedger $ledger) {}

    public function award(User $user, string $eventType, ?string $sourceType = null, ?int $sourceId = null): ?BreezeBucksTransaction
    {
        $rule = RewardRule::where('event_type', $eventType)->first();

        if (! $rule || ! $rule->enabled || ! $rule->allowsRole($user->role)) {
            return null;
        }

        $transaction = $this->ledger->record(
            $user,
            BreezeBucksTransaction::TYPE_EARNED,
            $rule->points,
            $rule->description,
            $sourceType,
            $sourceId,
        );

        $user->notify(new BreezeBucksAwarded($transaction));

        return $transaction;
    }
}
