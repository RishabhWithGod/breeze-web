<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * What a plan costs for a billing cycle.
 *
 * The one place the sum is done: the Payment Setup screen shows a live total
 * worked out in the browser, and the server works it out again from here before
 * anything is recorded, so the two cannot drift. Everything is in whole cents.
 */
class PlanPricing
{
    /**
     * @return array{
     *     plan: string, cycle: string, months: int,
     *     subtotal: int, annualDiscountPercent: int, annualDiscount: int, total: int
     * }
     */
    public function quote(string $planKey, string $cycle): array
    {
        $plan = config("subscription.plans.{$planKey}")
            ?? throw new InvalidArgumentException("Unknown plan [{$planKey}].");

        $months = $cycle === 'yearly' ? 12 : 1;
        $subtotal = (int) round($plan['price'] * 100) * $months;

        $annualPercent = $cycle === 'yearly' ? (int) config('subscription.annual_discount_percent') : 0;
        $annual = (int) round($subtotal * $annualPercent / 100);

        return [
            'plan' => $planKey,
            'cycle' => $cycle,
            'months' => $months,
            'subtotal' => $subtotal,
            'annualDiscountPercent' => $annualPercent,
            'annualDiscount' => $annual,
            'total' => $subtotal - $annual,
        ];
    }
}
