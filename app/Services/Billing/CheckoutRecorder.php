<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\SubscriptionCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a paid Stripe Checkout session as the account's subscription — the one place
 * both the web's Payment Setup and the phone's confirm step do it, so they cannot drift.
 */
class CheckoutRecorder
{
    /**
     * Whether a Stripe session really is this person's, finished and paid for a known plan.
     *
     * @param  array<string, mixed>  $session
     */
    public function isGenuine(User $user, array $session): bool
    {
        $plan = $session['metadata']['plan'] ?? null;
        $cycle = $session['metadata']['cycle'] ?? null;

        return ($session['client_reference_id'] ?? null) === (string) $user->id
            && ($session['status'] ?? null) === 'complete'
            && ($session['payment_status'] ?? null) === 'paid'
            && config("subscription.plans.{$plan}") !== null
            && in_array($cycle, ['monthly', 'yearly'], true);
    }

    /** @param  array<string, mixed>  $session */
    public function record(User $user, array $session, string $plan, string $cycle, int $seats): void
    {
        $stripeSubscription = is_array($session['subscription'] ?? null) ? $session['subscription'] : [];
        $card = $stripeSubscription['default_payment_method']['card'] ?? [];
        $details = $session['customer_details'] ?? [];
        $address = $details['address'] ?? [];

        DB::transaction(function () use ($user, $session, $plan, $cycle, $seats, $stripeSubscription, $card, $details, $address) {
            $existing = Subscription::query()->where('user_id', $user->id)->first();

            Subscription::updateOrCreate(['user_id' => $user->id], [
                'plan' => $plan,
                'seats' => $seats,
                'status' => 'active',
                'billing_cycle' => $cycle,
                'renews_on' => $this->renewsOn($stripeSubscription, $cycle),
                'stripe_customer_id' => is_string($session['customer'] ?? null) ? $session['customer'] : null,
                'stripe_subscription_id' => $stripeSubscription['id'] ?? null,
                'confirmation_number' => $existing?->confirmation_number ?? Subscription::newConfirmationNumber(),
                'receipt_url' => $stripeSubscription['latest_invoice']['hosted_invoice_url'] ?? $existing?->receipt_url,
                'updated_by' => $user->id,
            ]);

            if ($card !== []) {
                SubscriptionCard::updateOrCreate(['user_id' => $user->id], [
                    'cardholder_name' => $details['name'] ?? $user->name,
                    'brand' => $this->brand($card['brand'] ?? ''),
                    'last_four' => $card['last4'] ?? '0000',
                    'exp_month' => $card['exp_month'] ?? 1,
                    'exp_year' => $card['exp_year'] ?? now()->year,
                    'address_line1' => $address['line1'] ?? null,
                    'address_line2' => $address['line2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'postal_code' => $address['postal_code'] ?? null,
                    'country' => $address['country'] ?? null,
                ]);
            }

            $user->forceFill(['needs_payment_setup' => false])->save();
        });
    }

    /** The date Stripe will bill next, wherever this API version keeps it. */
    private function renewsOn(array $subscription, string $cycle): string
    {
        $end = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;

        return $end
            ? now()->setTimestamp((int) $end)->toDateString()
            : ($cycle === 'yearly' ? now()->addYear() : now()->addMonth())->toDateString();
    }

    private function brand(string $stripeBrand): string
    {
        return match (strtolower($stripeBrand)) {
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            default => ucfirst($stripeBrand ?: 'Card'),
        };
    }
}
