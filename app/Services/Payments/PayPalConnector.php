<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

/** Tests PayPal REST API credentials via the real OAuth token endpoint — no SDK. */
class PayPalConnector implements ProcessorConnector
{
    public function requiredCredentialFields(): array
    {
        return [
            'client_id' => 'Client ID',
            'client_secret' => 'Client Secret',
        ];
    }

    public function test(array $credentials): ProcessorTestResult
    {
        $clientId = trim((string) ($credentials['client_id'] ?? ''));
        $clientSecret = trim((string) ($credentials['client_secret'] ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            return ProcessorTestResult::fail('A client ID and client secret are required.');
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->timeout(10)
                ->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                ]);
        } catch (\Throwable $e) {
            return ProcessorTestResult::fail('Could not reach PayPal: '.$e->getMessage());
        }

        if ($response->successful() && $response->json('access_token')) {
            return ProcessorTestResult::ok('Connected to PayPal successfully.');
        }

        $error = $response->json('error_description') ?? "PayPal returned HTTP {$response->status()}.";

        return ProcessorTestResult::fail($error);
    }
}
