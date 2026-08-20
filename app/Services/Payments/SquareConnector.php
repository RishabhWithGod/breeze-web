<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;

/** Tests a Square access token against Square's real API — no SDK, one real HTTP call. */
class SquareConnector implements ProcessorConnector
{
    public function requiredCredentialFields(): array
    {
        return ['access_token' => 'Access Token'];
    }

    public function test(array $credentials): ProcessorTestResult
    {
        $accessToken = trim((string) ($credentials['access_token'] ?? ''));

        if ($accessToken === '') {
            return ProcessorTestResult::fail('An access token is required.');
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Square-Version' => '2024-01-18'])
                ->timeout(10)
                ->get('https://connect.squareupsandbox.com/v2/locations');
        } catch (\Throwable $e) {
            return ProcessorTestResult::fail('Could not reach Square: '.$e->getMessage());
        }

        if ($response->successful()) {
            return ProcessorTestResult::ok('Connected to Square successfully.');
        }

        $error = $response->json('errors.0.detail') ?? "Square returned HTTP {$response->status()}.";

        return ProcessorTestResult::fail($error);
    }
}
