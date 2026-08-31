<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceRecognizer;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The login-time half of 2FA: a real credential check already happened in
 * `LoginRequest::authenticate()`, but `Auth::login()` is deliberately
 * withheld until this challenge is passed — an account with 2FA "Enabled"
 * that never gets challenged at sign-in would be a fake security state.
 */
class TwoFactorChallengeController extends Controller
{
    private const PURPOSE = 'login_2fa';

    public function create(Request $request): Response|RedirectResponse
    {
        $userId = $request->session()->get('two_factor.user_id');

        if (! $userId) {
            return redirect()->route('login');
        }

        return Inertia::render('TwoFactorChallenge', [
            'maskedEmail' => User::find($userId)?->maskedEmail(),
        ]);
    }

    public function resend(Request $request, OtpChallengeService $otp): RedirectResponse
    {
        $user = $this->pendingUser($request);
        $otp->issue($user, self::PURPOSE);

        return back()->with('success', 'A new code was sent.');
    }

    public function store(
        Request $request,
        OtpChallengeService $otp,
        TwoFactorService $twoFactor,
        SecurityEventLogger $logger,
        DeviceRecognizer $devices,
    ): RedirectResponse {
        $user = $this->pendingUser($request);

        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $verified = ! empty($validated['code'])
            ? $otp->verify($user, self::PURPOSE, $validated['code'])
            : (! empty($validated['recovery_code']) && $twoFactor->verifyRecoveryCode($user, $validated['recovery_code'], $request));

        if (! $verified) {
            $logger->log($user, SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED, 'A sign-in two-factor code failed to verify.', $request);

            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor.user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        $isNewDevice = $devices->recognize($user, $request);
        $logger->log($user, SecurityEvent::LOGIN_SUCCESS, 'A successful sign-in.', $request);

        if ($isNewDevice) {
            $logger->log($user, SecurityEvent::NEW_DEVICE_LOGIN, 'Sign-in from a device not seen before on this account.', $request);
        }

        return redirect()->intended(route('home'));
    }

    private function pendingUser(Request $request): User
    {
        $userId = $request->session()->get('two_factor.user_id');
        abort_unless($userId, 419);

        return User::findOrFail($userId);
    }
}
