<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

/** Tests a Stripe secret key against Stripe's real API — no SDK, one real HTTP call. */
class StripeConnector implements ProcessorConnector
{
    public function requiredCredentialFields(): array
    {
        return ['secret_key' => 'Secret Key'];
    }

    public function test(array $credentials): ProcessorTestResult
    {
        $secretKey = trim((string) ($credentials['secret_key'] ?? ''));

        if ($secretKey === '') {
            return ProcessorTestResult::fail('A secret key is required.');
        }

        try {
            $response = Http::withToken($secretKey)
                ->asForm()
                ->timeout(10)
                ->get('https://api.stripe.com/v1/balance');
        } catch (\Throwable $e) {
            return ProcessorTestResult::fail('Could not reach Stripe: '.$e->getMessage());
        }

        if ($response->successful()) {
            return ProcessorTestResult::ok('Connected to Stripe successfully.');
        }

        $error = $response->json('error.message') ?? "Stripe returned HTTP {$response->status()}.";

        return ProcessorTestResult::fail($error);
    }

    /**
     * The only currency this app ever charges in. Fixed here — not read
     * from `BillingSetting::default_currency` or any other input — so a
     * session can never be created in anything but USD, no matter what a
     * caller passes in.
     */
    private const CURRENCY = 'usd';

    /**
     * Creates a real Stripe Checkout Session for one invoice's outstanding
     * balance — one line item, the invoice's own number as the description,
     * no card details ever touch this app's own servers.
     *
     * USD-only, enforced server-side: `price_data.currency` is the fixed
     * `self::CURRENCY` constant, not client input, so Stripe cannot convert
     * or present the buyer any other currency. `adaptive_pricing[enabled]`
     * is explicitly turned off — Stripe's Adaptive Pricing otherwise
     * re-presents a fixed-currency line item in the buyer's local currency
     * (e.g. INR) by IP/location, overriding `price_data.currency` even
     * though this call never uses a Dashboard Price object. Account-level
     * Adaptive Pricing defaults only decide the *fallback* when a session
     * doesn't say either way, so this must be set on every session — it
     * can't be left to whatever the Dashboard currently has configured.
     * `payment_method_types` is pinned to `card` rather than left
     * "automatic", which keeps Stripe from offering region-specific local
     * payment methods for the amount. No billing/shipping address is
     * collected, so there is no full address form for the buyer to change —
     * but Checkout's card entry always shows a "Country or region" field of
     * its own (used to determine the postal-code format for card
     * verification), and that one defaults from the buyer's IP/browser
     * locale unless a `customer` with an address on file is attached to the
     * session. `createUsCustomer()` creates a throwaway Customer with
     * `address.country = US` for exactly that purpose, so the field starts
     * on United States instead of wherever the buyer is browsing from.
     *
     * @return array{url: string, id: string}|null Null on any failure; the caller decides how to surface that.
     */
    public function createCheckoutSession(
        string $secretKey,
        string $invoiceNumber,
        float $amount,
        string $successUrl,
        string $cancelUrl,
        string $clientReferenceId,
    ): ?array {
        $customerId = $this->createUsCustomer($secretKey);

        $response = Http::asForm()
            ->withToken($secretKey)
            ->timeout(15)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $clientReferenceId,
                'payment_method_types' => ['card'],
                'adaptive_pricing' => ['enabled' => 'false'],
                ...($customerId ? ['customer' => $customerId] : []),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => self::CURRENCY,
                        'unit_amount' => (int) round($amount * 100),
                        'product_data' => [
                            'name' => "Invoice {$invoiceNumber}",
                        ],
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $url = $response->json('url');
        $id = $response->json('id');

        return $url && $id ? ['url' => $url, 'id' => $id] : null;
    }

    /**
     * Creates a fresh, minimal Stripe Customer whose only purpose is to
     * carry `address.country = US` onto the Checkout Session that attaches
     * it, so Checkout's country field starts on United States instead of
     * geolocating the buyer. Not tied to this app's own client records —
     * nothing existing is read or written. Failure here must never block a
     * payment, so this returns null (letting the session fall back to
     * Stripe's default geolocated behaviour) rather than throwing.
     */
    private function createUsCustomer(string $secretKey): ?string
    {
        try {
            $response = Http::asForm()
                ->withToken($secretKey)
                ->timeout(15)
                ->post('https://api.stripe.com/v1/customers', [
                    'address' => ['country' => 'US'],
                ]);
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->json('id') : null;
    }

    /**
     * Looks a session back up from Stripe itself when the buyer returns —
     * the only source of truth for whether it actually paid, since a
     * `success_url` visit alone proves nothing (anyone can type that URL).
     *
     * @return array{paid: bool, amountTotal: float, paymentIntentId: ?string}|null Null if the session can't be read at all.
     */
    public function retrieveCheckoutSession(string $secretKey, string $sessionId): ?array
    {
        $response = Http::withToken($secretKey)
            ->timeout(15)
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

        if (! $response->successful()) {
            return null;
        }

        return [
            'paid' => $response->json('payment_status') === 'paid',
            'amountTotal' => ((int) $response->json('amount_total', 0)) / 100,
            'paymentIntentId' => $response->json('payment_intent'),
        ];
    }
}
