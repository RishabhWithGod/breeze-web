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
}
