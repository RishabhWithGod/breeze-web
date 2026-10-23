<?php

namespace App\Services\Push;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends push notifications through Firebase Cloud Messaging's HTTP v1 API, signing in with a
 * service account. Built on the HTTP client rather than the Firebase SDK so it adds no dependency.
 *
 * Does nothing until `services.fcm` is configured, so every environment without Firebase keeps
 * working exactly as before.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function enabled(): bool
    {
        return filled(config('services.fcm.project_id')) && $this->credentials() !== null;
    }

    /**
     * @param  array<string, string>  $data  FCM only accepts string values here.
     */
    public function send(DeviceToken $device, string $title, string $body, array $data = [], ?int $badge = null): void
    {
        $projectId = config('services.fcm.project_id');

        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'general_alerts']],
                    'apns' => ['payload' => ['aps' => array_filter(['sound' => 'default', 'badge' => $badge], fn ($v) => $v !== null)]],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        // The phone uninstalled the app or its token was replaced — it can never receive again.
        $status = $response->json('error.status');
        if ($response->status() === 404 || in_array($status, ['NOT_FOUND', 'UNREGISTERED'], true)) {
            $device->delete();

            return;
        }
        if ($status === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'token')) {
            $device->delete();

            return;
        }

        Log::warning('FCM send failed', ['status' => $response->status(), 'body' => $response->body()]);
        throw new RuntimeException('FCM send failed: '.$response->status());
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () {
            $credentials = $this->credentials() ?? throw new RuntimeException('FCM credentials are not configured.');
            $uri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();

            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $uri,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign("{$header}.{$claims}", $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

            $response = Http::asForm()->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "{$header}.{$claims}.".$this->base64Url($signature),
            ])->throw();

            return $response->json('access_token');
        });
    }

    /** @return array<string, mixed>|null */
    private function credentials(): ?array
    {
        $path = config('services.fcm.credentials');
        if (blank($path)) {
            return null;
        }
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) && isset($json['client_email'], $json['private_key']) ? $json : null;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
