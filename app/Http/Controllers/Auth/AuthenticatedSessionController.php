<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceRecognizer;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /** Name of the cookie that pre-fills the email after "Remember me". */
    private const REMEMBERED_EMAIL = 'breeze_remembered_email';

    public function __construct(
        private readonly SecurityEventLogger $securityEventLogger,
        private readonly DeviceRecognizer $deviceRecognizer,
    ) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Login', [
            'rememberedEmail' => $request->cookie(self::REMEMBERED_EMAIL),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $requiresTwoFactor = $request->authenticate();
        $request->session()->regenerate();

        if ($requiresTwoFactor) {
            $email = $request->string('email')->lower()->toString();
            $user = User::query()->where('email', $email)->first();

            $request->session()->put('two_factor.user_id', $user->id);
            $request->session()->put('two_factor.remember', $request->boolean('remember'));

            app(OtpChallengeService::class)->issue($user, 'login_2fa');

            return redirect()->route('two-factor.challenge.create');
        }

        $this->completeLogin($request, $request->string('email')->lower()->toString());

        $response = redirect()->intended(route('home'));

        // "Remember me" both extends the session and pre-fills the address next
        // time, matching the prototype's behaviour.
        return $request->boolean('remember')
            ? $response->withCookie(cookie()->forever(self::REMEMBERED_EMAIL, $request->string('email')->lower()->toString()))
            : $response->withCookie(cookie()->forget(self::REMEMBERED_EMAIL));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function completeLogin(Request $request, string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return;
        }

        Auth::login($user, $request->boolean('remember'));

        $isNewDevice = $this->deviceRecognizer->recognize($user, $request);

        $this->securityEventLogger->log($user, SecurityEvent::LOGIN_SUCCESS, 'A successful sign-in.', $request);

        if ($isNewDevice) {
            $this->securityEventLogger->log($user, SecurityEvent::NEW_DEVICE_LOGIN, 'Sign-in from a device not seen before on this account.', $request);
        }
    }
}
