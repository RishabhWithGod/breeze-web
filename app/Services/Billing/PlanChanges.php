<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\User;
use RuntimeException;

/**
 * Moving a company onto another plan, or between monthly and yearly billing.
 *
 * On a paid Stripe subscription the change is made at Stripe first — the pro-rata difference is
 * charged or credited there — and only then recorded here, so the plan on screen is always the plan
 * being billed. Without a Stripe subscription it is only recorded outside production (nothing real
 * could be charged), never on a live site.
 */
class PlanChanges
{
    public function __construct(
        private readonly SeatPurchases $seats,
        private readonly StripeSubscriptions $stripe,
    ) {}

    /**
     * @return array{plan: string, cycle: string, charged: int|null, via: string}
     *
     * @throws RuntimeException when the payment could not be taken or the change cannot be made here
     */
    public function change(User $actor, string $plan, string $cycle): array
    {
        $subscription = Subscription::forUser($actor);
        $via = $this->seats->via($subscription)
            ?? throw new RuntimeException('The plan cannot be changed from here. Please contact support.');

        $charged = null;
        $renewsOn = null;

        if ($via === SeatPurchases::VIA_STRIPE) {
            // Paid first: if Stripe refuses the card, the plan has not moved.
            $result = $this->stripe->setPlan((string) $subscription->stripe_subscription_id, $plan, $cycle, (int) $subscription->seats);
            $charged = $result['charged'];
            $renewsOn = $result['renewsOn'];
        }

        $cycleChanged = $subscription->billing_cycle !== $cycle;

        $subscription->update([
            'plan' => $plan,
            'billing_cycle' => $cycle,
            // Stripe's own date when it gave one; otherwise a different cycle length starts from today.
            ...($renewsOn !== null
                ? ['renews_on' => $renewsOn]
                : ($cycleChanged ? ['renews_on' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth()] : [])),
            'updated_by' => $actor->id,
        ]);

        return ['plan' => $plan, 'cycle' => $cycle, 'charged' => $charged, 'via' => $via];
    }
}
