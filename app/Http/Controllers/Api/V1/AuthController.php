<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Services\Security\DeviceRecognizer;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mobile authentication — token-based (Sanctum), not the web app's session
 * cookie.
 *
 * `login()` reuses `LoginRequest::authenticate()` verbatim: the same
 * validation, the same 5-attempt rate limiting, the same password check,
 * the same `SecurityEvent::LOGIN_FAILED` audit trail on a bad attempt, and
 * the same two-factor detection. A mobile-only reimplementation of any of
 * that would have quietly let a 2FA-protected account sign in from a phone
 * with a password alone — reusing the FormRequest closes that gap by
 * construction rather than by remembering to copy the check.
 */
class AuthController extends Controller
{
    use ApiResponses;

    private const OTP_PURPOSE = 'login_2fa';

    public function login(LoginRequest $request): JsonResponse
    {
        $requiresTwoFactor = $request->authenticate();

        $user = User::query()->where('email', Str::lower($request->string('email')->toString()))->firstOrFail();

        if ($requiresTwoFactor) {
            app(OtpChallengeService::class)->issue($user, self::OTP_PURPOSE);

            return $this->ok([
                'requiresTwoFactor' => true,
                'email' => $user->email,
            ], 'A verification code was sent to your email.');
        }

        return $this->ok($this->issueSession($user, $request), 'Signed in.');
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if (! $user || ! app(OtpChallengeService::class)->verify($user, self::OTP_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired.',
            ]);
        }

        return $this->ok($this->issueSession($user, $request), 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return $this->ok([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'initials' => $user->initials,
        ]);
    }

    /**
     * The same audit trail the web login gives a successful sign-in — a
     * new device notice included — just ending in a Sanctum token instead
     * of a session.
     *
     * @return array<string, mixed>
     */
    private function issueSession(User $user, Request $request): array
    {
        $isNewDevice = app(DeviceRecognizer::class)->recognize($user, $request);

        app(SecurityEventLogger::class)->log($user, SecurityEvent::LOGIN_SUCCESS, 'Signed in from the mobile app.', $request);

        if ($isNewDevice) {
            app(SecurityEventLogger::class)->log($user, SecurityEvent::NEW_DEVICE_LOGIN, 'Signed in from a new device (mobile app).', $request);
        }

        $expiration = config('sanctum.expiration');
        $token = $user->createToken(
            'mobile-'.now()->format('Ymd-His'),
            ['*'],
            $expiration ? now()->addMinutes((int) $expiration) : null,
        );

        return [
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'initials' => $user->initials,
            ],
            'twoFactorEnabled' => UserSecuritySetting::forUser($user)->two_factor_enabled,
        ];
    }
}
