<?php

namespace App\Notifications;

use App\Models\BreezeBucksRedemption;
use App\Models\BreezeBucksTransaction;
use App\Notifications\Channels\AppNotificationChannel;
use Illuminate\Notifications\Notification;

/**
 * One notification class covering every ledger movement — earned, bonus,
 * redeemed, adjustment, reversal — since they're all just a signed
 * transaction with a description; the wording only needs to read naturally
 * for each `type`/sign, not branch into five notification classes.
 */
class BreezeBucksAwarded extends Notification
{
    public function __construct(
        public readonly BreezeBucksTransaction $transaction,
        public readonly ?BreezeBucksRedemption $redemption = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [AppNotificationChannel::class];
    }

    /** @return array<string, mixed> */
    public function toAppNotification(object $notifiable): array
    {
        $link = route('breeze-bucks.index', absolute: false);
        $amount = $this->transaction->amount;
        $sign = $amount >= 0 ? '+' : '';

        return [
            'type' => 'breeze-bucks-'.$this->transaction->type,
            'title' => $this->title(),
            'detail' => "{$sign}{$amount} BB — {$this->transaction->description}",
            'link' => $link,
            'data' => ['actions' => [['label' => 'View Breeze Bucks', 'href' => $link]]],
        ];
    }

    private function title(): string
    {
        return match ($this->transaction->type) {
            BreezeBucksTransaction::TYPE_EARNED => 'Breeze Bucks earned',
            BreezeBucksTransaction::TYPE_BONUS => 'Breeze Bucks bonus',
            BreezeBucksTransaction::TYPE_REDEEMED => 'Reward redeemed',
            BreezeBucksTransaction::TYPE_ADJUSTMENT => 'Breeze Bucks adjusted',
            BreezeBucksTransaction::TYPE_REVERSAL => 'Breeze Bucks reversed',
            default => 'Breeze Bucks update',
        };
    }
}
