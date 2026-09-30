<?php

namespace App\Services\Billing;

use App\Models\PaymentProcessor;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Takes a subscription payment through Stripe Checkout.
 *
 * The card is entered on Stripe's own page, never here, so no card number ever
 * reaches this app and the page is served over HTTPS whatever this site is.
 */
class StripeSubscriptions
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private readonly PlanPricing $pricing) {}

    /**
     * Breeze.Ai's Stripe secret key. Outside production a company's connected
     * Stripe test key stands in when none is set, so the flow can be tried
     * locally; in production only the configured key is ever used, so a
     * subscription can never be paid to a customer's own account.
     */
    public function secretKey(): ?string
    {
        $configured = config('services.stripe.secret');

        if (filled($configured)) {
            return $configured;
        }

        if (app()->isProduction()) {
            return null;
        }

        $key = PaymentProcessor::where('key', 'stripe')->first()?->credentials['secret_key'] ?? null;

        return is_string($key) && str_starts_with($key, 'sk_test_') ? $key : null;
    }

    public function configured(): bool
    {
        return $this->secretKey() !== null;
    }

    /**
     * A Checkout Session that charges the plan now and bills it again each period.
     *
     * The price is the plan's price for the cycle, worked out here rather than
     * taken from the browser.
     *
     * @return array{url: string, id: string}
     */
    public function createCheckout(User $user, string $plan, string $cycle, string $successUrl, string $cancelUrl): array
    {
        $quote = $this->pricing->quote($plan, $cycle);
        $name = config("subscription.plans.{$plan}.name");
        $interval = $cycle === 'yearly' ? 'year' : 'month';
        $metadata = ['user_id' => (string) $user->id, 'plan' => $plan, 'cycle' => $cycle];

        $response = $this->post('/checkout/sessions', [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $user->id,
            // A customer whose address is already in the United States, so the
            // page's country field starts there rather than wherever the buyer
            // happens to be browsing from.
            'customer' => $this->createUsCustomer($user),
            'customer_update' => ['address' => 'auto', 'name' => 'auto'],
            'payment_method_types' => ['card'],
            // Dollars only: Stripe's Adaptive Pricing would otherwise re-show the
            // price in the buyer's local currency.
            'adaptive_pricing' => ['enabled' => 'false'],
            'locale' => 'en',
            'billing_address_collection' => 'required',
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $quote['total'],
                    'recurring' => ['interval' => $interval],
                    'product_data' => [
                        'name' => "Breeze.Ai {$name} — ".($cycle === 'yearly' ? 'Annual' : 'Monthly'),
                    ],
                ],
            ]],
        ]);

        if (! is_string($response['url'] ?? null) || ! is_string($response['id'] ?? null)) {
            throw new RuntimeException('Stripe did not return a checkout page.');
        }

        return ['url' => $response['url'], 'id' => $response['id']];
    }

    /** A Stripe Customer for this person, with a United States address to start from. */
    private function createUsCustomer(User $user): string
    {
        $customer = $this->post('/customers', [
            'email' => $user->email,
            'name' => $user->name,
            'address' => ['country' => 'US'],
            'metadata' => ['user_id' => (string) $user->id],
        ]);

        return $customer['id'] ?? throw new RuntimeException('Stripe did not create a customer.');
    }

    /**
     * Looks a finished session back up from Stripe — the only proof a payment
     * happened, since anyone can type the success address.
     *
     * @return array<string, mixed>
     */
    public function retrieveSession(string $sessionId): array
    {
        $response = Http::withToken($this->requireKey())
            ->timeout(20)
            ->get(self::API."/checkout/sessions/{$sessionId}", [
                'expand' => ['subscription.default_payment_method', 'subscription.latest_invoice'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?? 'Stripe could not find that payment.');
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::asForm()->withToken($this->requireKey())->timeout(20)->post(self::API.$path, $payload);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?? "Stripe returned HTTP {$response->status()}.");
        }

        return $response->json();
    }

    private function requireKey(): string
    {
        return $this->secretKey() ?? throw new RuntimeException('Stripe is not set up for subscriptions.');
    }
}
