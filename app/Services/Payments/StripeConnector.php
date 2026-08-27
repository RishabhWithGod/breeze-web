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
     * Creates a real Stripe Checkout Session for one invoice's outstanding
     * balance — one line item, the invoice's own number as the description,
     * no card details ever touch this app's own servers.
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
        $response = Http::asForm()
            ->withToken($secretKey)
            ->timeout(15)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $clientReferenceId,
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => 'usd',
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
