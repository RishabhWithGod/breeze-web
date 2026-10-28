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
     * taken from the browser. Billed as Stripe's own quantity, not a single
     * pre-multiplied amount, so a seat count really is per-seat on the invoice
     * and in the Stripe dashboard, not just in this app's own math.
     *
     * `allow_promotion_codes` lets a real Stripe Promotion Code (e.g. a
     * convention discount) be redeemed on Stripe's own page — nothing here
     * grants a discount itself.
     *
     * @return array{url: string, id: string}
     */
    public function createCheckout(User $user, string $plan, string $cycle, int $seats, string $successUrl, string $cancelUrl): array
    {
        // Per seat, per cycle — not the already-multiplied total. Stripe does
        // quantity × unit_amount itself, which is what makes this a real
        // Stripe-native per-seat subscription rather than one flat line Stripe
        // (and the dashboard reading it later) has no idea is seats at all.
        $perSeatQuote = $this->pricing->quote($plan, $cycle, 1);
        $name = config("subscription.plans.{$plan}.name");
        $interval = $cycle === 'yearly' ? 'year' : 'month';
        $metadata = ['user_id' => (string) $user->id, 'plan' => $plan, 'cycle' => $cycle, 'seats' => (string) $seats];

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
            'allow_promotion_codes' => 'true',
            // Dollars only: Stripe's Adaptive Pricing would otherwise re-show the
            // price in the buyer's local currency.
            'adaptive_pricing' => ['enabled' => 'false'],
            'locale' => 'en',
            'billing_address_collection' => 'required',
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'line_items' => [[
                'quantity' => max(1, $seats),
                'price_data' => [
                    'currency' => 'usd',
                    'unit_amount' => $perSeatQuote['total'],
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

    /**
     * Sets how many seats a live subscription bills for, charging the pro-rata difference now.
     *
     * The saved payment method is charged straight away (`always_invoice`); if that payment
     * fails the quantity is not changed (`error_if_incomplete`), so a seat is never given that
     * was not paid for.
     *
     * @return array{quantity: int, charged: int|null} `charged` in cents, when Stripe says what was billed
     */
    public function setSeatQuantity(string $subscriptionId, int $quantity): array
    {
        $current = Http::withToken($this->requireKey())->timeout(20)->get(self::API."/subscriptions/{$subscriptionId}");

        if (! $current->successful()) {
            throw new RuntimeException($current->json('error.message') ?? 'Stripe could not find this subscription.');
        }

        $item = $current->json('items.data.0.id');
        if (! is_string($item)) {
            throw new RuntimeException('This subscription has no seat line to change.');
        }

        $updated = $this->post("/subscriptions/{$subscriptionId}", [
            'items' => [['id' => $item, 'quantity' => $quantity]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'error_if_incomplete',
            'expand' => ['latest_invoice'],
        ]);

        $paid = $updated['latest_invoice']['amount_paid'] ?? null;

        return [
            'quantity' => (int) ($updated['items']['data'][0]['quantity'] ?? $quantity),
            'charged' => is_numeric($paid) ? (int) $paid : null,
        ];
    }

    /**
     * Moves a live subscription onto another plan and/or billing cycle, keeping its seat count.
     *
     * The difference for the rest of the period is invoiced and charged now (`always_invoice`):
     * an upgrade costs the pro-rata extra, a downgrade is credited. If the payment fails nothing
     * changes (`error_if_incomplete`), so a plan is never given that was not paid for.
     *
     * @return array{charged: int|null, renewsOn: string|null} `charged` in cents; `renewsOn` as Stripe now has it
     */
    public function setPlan(string $subscriptionId, string $plan, string $cycle, int $seats): array
    {
        $current = Http::withToken($this->requireKey())->timeout(20)->get(self::API."/subscriptions/{$subscriptionId}");

        if (! $current->successful()) {
            throw new RuntimeException($current->json('error.message') ?? 'Stripe could not find this subscription.');
        }

        $item = $current->json('items.data.0.id');
        if (! is_string($item)) {
            throw new RuntimeException('This subscription has no plan line to change.');
        }

        // Same per-seat unit amount a new checkout would charge — worked out here, never taken from the browser.
        $perSeat = $this->pricing->quote($plan, $cycle, 1)['total'];
        $name = config("subscription.plans.{$plan}.name");
        $product = $this->post('/products', ['name' => "Breeze.Ai {$name} — ".($cycle === 'yearly' ? 'Annual' : 'Monthly')]);

        $updated = $this->post("/subscriptions/{$subscriptionId}", [
            'items' => [[
                'id' => $item,
                'quantity' => max(1, $seats),
                'price_data' => [
                    'currency' => 'usd',
                    'product' => $product['id'] ?? throw new RuntimeException('Stripe did not create the plan.'),
                    'unit_amount' => $perSeat,
                    'recurring' => ['interval' => $cycle === 'yearly' ? 'year' : 'month'],
                ],
            ]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'error_if_incomplete',
            'metadata' => ['plan' => $plan, 'cycle' => $cycle, 'seats' => (string) $seats],
            'expand' => ['latest_invoice'],
        ]);

        $paid = $updated['latest_invoice']['amount_paid'] ?? null;
        $end = $updated['current_period_end'] ?? $updated['items']['data'][0]['current_period_end'] ?? null;

        return [
            'charged' => is_numeric($paid) ? max(0, (int) $paid) : null,
            'renewsOn' => is_numeric($end) ? \Illuminate\Support\Carbon::createFromTimestampUTC((int) $end)->toDateString() : null,
        ];
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
