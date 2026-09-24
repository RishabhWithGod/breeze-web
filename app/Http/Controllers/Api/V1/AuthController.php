<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TechnicianRegistered;
use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterTechnicianRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Services\Security\DeviceRecognizer;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use App\Support\UsPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
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

    private const PASSWORD_RESET_PURPOSE = 'password_reset';

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

    /**
     * A field technician creating their own account.
     *
     * Lands `pending_approval`, not active — `EnsureAccountIsActive` blocks
     * every job/task/timer/etc. endpoint until a manager approves them on the
     * web app, but a token is issued immediately regardless, so the app can
     * poll `/auth/me` and show its own status rather than being stuck unable
     * to authenticate at all.
     */
    public function register(RegisterTechnicianRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            // Stored formatted, not raw — same rule every other phone
            // column in this app holds to (see `UsPhone`), so a
            // self-registered technician's number reads the same as one a
            // manager typed in on web.
            'phone' => UsPhone::format($data['phone'] ?? null),
            'password' => Hash::make($data['password']),
            // A manager corrects this to 'Foreman' or 'Apprentice' from the
            // Teams page if that's what the person actually is —
            // 'Journeyman' is the default every mobile signup starts as, not
            // a claim about the real crew hierarchy.
            'role' => 'Journeyman',
        ]);

        // `status`/`registration_source` are deliberately not in `$fillable`
        // (nothing should ever be able to mass-assign its own approval), so
        // they are set directly here rather than passed to `create()`, which
        // would silently drop them.
        $user->status = User::STATUS_PENDING_APPROVAL;
        $user->registration_source = User::SOURCE_MOBILE;
        $user->save();

        TechnicianRegistered::dispatch($user);

        return $this->created($this->issueSession($user, $request), 'Account created — awaiting manager approval.');
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

    /**
     * Issues a 6-digit reset code by email — the same `OtpChallengeService`
     * every other verification step in this app uses, rather than the web
     * login's `Password::sendResetLink()`, which mails a browser link a
     * mobile client has nowhere sensible to open.
     *
     * Always reports success, whether or not the address exists — the same
     * anti-enumeration rule `PasswordResetLinkController` (web) follows.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();
        if ($user) {
            app(OtpChallengeService::class)->issue($user, self::PASSWORD_RESET_PURPOSE);
        }

        return $this->ok(null, 'If that email has an account, a reset code has been sent.');
    }

    /**
     * Verifies the code from {@see forgotPassword} and sets a new password in
     * the same call — there is no separate "verify only" step, so a wrong
     * code is only ever discovered here, against the real, final attempt.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::query()->where('email', Str::lower($data['email']))->first();

        if (! $user || ! app(OtpChallengeService::class)->verify($user, self::PASSWORD_RESET_PURPOSE, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired.',
            ]);
        }

        $user->update(['password' => $data['password']]);

        // A password reset is exactly the moment a stolen prior token should
        // stop working — every token this account holds is revoked, not just
        // the request's own (there isn't one yet; this endpoint is guest).
        $user->tokens()->delete();

        app(SecurityEventLogger::class)->log($user, SecurityEvent::PASSWORD_CHANGED, 'Password was reset from the mobile app.', $request);

        return $this->ok(null, 'Password reset. Please sign in with your new password.');
    }

    /**
     * Changes the signed-in technician's own password — the mobile
     * equivalent of `SecuritySettingsController::updatePassword()` (web).
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is incorrect.',
            ]);
        }

        $user->update(['password' => $data['password']]);

        // Every other token this account holds is revoked — the same rule
        // the web change-password flow applies to sessions — keeping only
        // the one making this request.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        app(SecurityEventLogger::class)->log($user, SecurityEvent::PASSWORD_CHANGED, 'Password was changed from the mobile app.', $request);

        return $this->ok(null, 'Password updated.');
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
            'status' => $user->status,
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
                'status' => $user->status,
            ],
            'twoFactorEnabled' => UserSecuritySetting::forUser($user)->two_factor_enabled,
        ];
    }
}
