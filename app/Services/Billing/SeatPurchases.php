<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Buying more seats on the plan a company is already on.
 *
 * Seats are what is billed: a Stripe subscription carries a quantity, and raising it charges the
 * pro-rata difference for the rest of the period, then the larger amount each period after.
 * Where there is no Stripe subscription to change (an account from before Stripe, or a developer's
 * machine), seats are only added outside production — nothing real could be charged there, so
 * none is ever given away on a live site.
 */
class SeatPurchases
{
    /** The most that can be added in one go. A bigger team is a conversation, not a tap. */
    public const MAX_ADD = 100;

    /** The most seats one company can hold through this screen. */
    public const MAX_SEATS = 500;

    public const VIA_STRIPE = 'stripe';

    public const VIA_LOCAL = 'local';

    public function __construct(
        private readonly PlanPricing $pricing,
        private readonly StripeSubscriptions $stripe,
    ) {}

    /**
     * What the Buy Seats dialog needs: the price of one more seat, and how it would be paid.
     *
     * @return array<string, mixed>
     */
    public function offer(?User $user): array
    {
        $subscription = Subscription::forUser($user);
        $perSeat = $this->pricing->quote($subscription->planKey(), $subscription->billing_cycle, 1)['total'];
        $via = $this->via($subscription);

        return [
            'planName' => config("subscription.plans.{$subscription->planKey()}.name"),
            'cycle' => $subscription->billing_cycle,
            // Cents, per seat, per billing cycle.
            'perSeatCents' => $perSeat,
            // False for an account on a plan that is no longer sold: its real rate is whatever Stripe
            // bills, so no figure of ours is shown as if it were the price.
            'priceKnown' => array_key_exists((string) $subscription->plan, config('subscription.plans', [])),
            'seats' => (int) $subscription->seats,
            'renewsOn' => $subscription->renews_on?->toDateString(),
            // Of the period still to run, as a share — what the pro-rata charge is worked from.
            'periodShare' => $this->periodShare($subscription),
            'via' => $via,
            'canBuy' => $via !== null,
            'maxAdd' => min(self::MAX_ADD, max(0, self::MAX_SEATS - (int) $subscription->seats)),
        ];
    }

    /**
     * Adds `$add` seats and returns what happened.
     *
     * @return array{added: int, seats: int, charged: int|null, via: string}
     *
     * @throws ValidationException when the number asked for is not allowed
     * @throws RuntimeException when the payment could not be taken
     */
    public function buy(User $actor, int $add): array
    {
        $subscription = Subscription::forUser($actor);

        if ($add < 1 || $add > self::MAX_ADD) {
            throw ValidationException::withMessages(['seats' => 'Add between 1 and '.self::MAX_ADD.' seats at a time.']);
        }

        $total = (int) $subscription->seats + $add;
        if ($total > self::MAX_SEATS) {
            throw ValidationException::withMessages(['seats' => 'That would be '.$total.' seats. Over '.self::MAX_SEATS.' seats, please contact us.']);
        }

        $via = $this->via($subscription)
            ?? throw new RuntimeException('Seats cannot be added to this account here. Please contact support.');

        $charged = null;
        if ($via === self::VIA_STRIPE) {
            // Paid first: if Stripe refuses the card, no seat has been given.
            $result = $this->stripe->setSeatQuantity((string) $subscription->stripe_subscription_id, $total);
            $total = $result['quantity'];
            $charged = $result['charged'];
        }

        $subscription->update(['seats' => $total, 'updated_by' => $actor->id]);

        return ['added' => $add, 'seats' => $total, 'charged' => $charged, 'via' => $via];
    }

    /** How a change to this subscription would be paid for, or null when it cannot be made here at all. */
    public function via(Subscription $subscription): ?string
    {
        if ($subscription->stripe_subscription_id !== null && $this->stripe->configured()) {
            return self::VIA_STRIPE;
        }

        return app()->isProduction() ? null : self::VIA_LOCAL;
    }

    /** The part of the current billing period that is still ahead, 0–1. */
    private function periodShare(Subscription $subscription): float
    {
        if ($subscription->renews_on === null) {
            return 1.0;
        }

        $end = $subscription->renews_on->copy()->startOfDay();
        $start = $subscription->billing_cycle === 'yearly' ? $end->copy()->subYear() : $end->copy()->subMonth();
        $length = max(1, (int) $start->diffInDays($end));
        $left = (int) now()->startOfDay()->diffInDays($end, false);

        return round(max(0, min($length, $left)) / $length, 4);
    }
}
