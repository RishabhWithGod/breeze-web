<?php

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\UserSecuritySetting;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The real security control center: 2FA enrollment, authentication method,
 * email/phone verification, per-event notification preferences, and the
 * immutable audit log — every field here is either a real stored value or
 * an honestly-labeled "not configured" state, never a fake demo status.
 *
 * Every action operates on `$request->user()` only — no route ever accepts
 * another user's id, so there is no ID to tamper with in the first place.
 */
class SecuritySettingsController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly OtpChallengeService $otp,
        private readonly SecurityEventLogger $logger,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $settings = UserSecuritySetting::forUser($user);
        $preferences = SecurityNotificationPreference::allForUser($user);

        $events = SecurityEvent::query()
            ->where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('SecuritySettings', [
            'twoFactor' => [
                'enabled' => $settings->two_factor_enabled,
                'method' => $settings->two_factor_method,
                'confirmedAt' => $settings->two_factor_confirmed_at?->toISOString(),
                'recoveryCodesRemaining' => $user->recoveryCodes()->whereNull('used_at')->count(),
            ],
            'smsConfigured' => false,
            'maskedPhone' => $user->maskedPhone(),
            'maskedEmail' => $user->maskedEmail(),
            'notificationPreferences' => $preferences->map(fn (SecurityNotificationPreference $preference) => [
                'eventType' => $preference->event_type,
                'label' => SecurityNotificationPreference::LABELS[$preference->event_type],
                'smsEnabled' => $preference->sms_enabled,
                'emailEnabled' => $preference->email_enabled,
            ]),
            'auditLog' => [
                'data' => $events->through(fn (SecurityEvent $event) => [
                    'id' => $event->id,
                    'activity' => $event->label(),
                    'description' => $event->description,
                    'ipAddress' => $event->ip_address ?? 'Unknown',
                    'location' => 'Unknown',
                    'occurredAt' => $event->occurred_at->toISOString(),
                ])->values(),
                'meta' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'total' => $events->total(),
                ],
            ],
        ]);
    }

    public function sendTwoFactorChallenge(Request $request): RedirectResponse
    {
        $this->twoFactor->sendEnableChallenge($request->user());

        return back()->with('success', 'A verification code was sent to your email.');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $result = $this->twoFactor->confirmEnable($request->user(), $data['code'], $request);

        if (! $result['success']) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        return back()->with('success', 'Two-factor authentication is now enabled.')
            ->with('recoveryCodes', $result['recoveryCodes']);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);

        if (! $this->twoFactor->disable($request->user(), $data['current_password'], $request)) {
            throw ValidationException::withMessages(['current_password' => 'That password is incorrect.']);
        }

        return back()->with('success', 'Two-factor authentication is now disabled.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'That password is incorrect.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes($request->user(), $request);

        return back()->with('recoveryCodes', $codes);
    }

    public function updateMethod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in([UserSecuritySetting::METHOD_EMAIL, UserSecuritySetting::METHOD_SMS])],
        ]);

        $this->twoFactor->setMethod($request->user(), $data['method'], $request);

        return back()->with('success', 'Authentication method updated.');
    }

    public function sendEmailChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)]]);

        $this->otp->issue($request->user(), 'change_email', $data['email']);

        return back()->with('success', 'A verification code was sent to the new address.');
    }

    public function confirmEmail(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $this->otp->verify($user, 'change_email', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $newEmail = $this->otp->target($user, 'change_email');
        $user->update(['email' => $newEmail, 'email_verified_at' => now()]);

        $this->logger->log($user, SecurityEvent::PROFILE_UPDATED, 'Email address was changed.', $request);

        return back()->with('success', 'Your email address was updated.');
    }

    public function sendPhoneChallenge(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        // Verified through the real email OTP mechanism, since no SMS
        // provider is configured to deliver a code to the new number itself.
        $this->otp->issue($request->user(), 'change_phone', $data['phone']);

        return back()->with('success', 'A verification code was sent to your email to confirm this change.');
    }

    public function confirmPhone(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $this->otp->verify($user, 'change_phone', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $newPhone = $this->otp->target($user, 'change_phone');
        $user->update(['phone' => $newPhone, 'phone_verified_at' => now()]);

        $this->logger->log($user, SecurityEvent::PROFILE_UPDATED, 'Phone number was changed.', $request);

        return back()->with('success', 'Your phone number was updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That password is incorrect.']);
        }

        $user->update(['password' => $data['password']]);

        // Every other session for this account is invalidated — a password
        // change is exactly the moment a stolen prior session should stop
        // working, on every device except this one.
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $this->logger->log($user, SecurityEvent::PASSWORD_CHANGED, 'Password was changed.', $request);

        return back()->with('success', 'Your password was updated.');
    }

    public function updateNotificationPreference(Request $request, string $eventType): RedirectResponse
    {
        abort_unless(in_array($eventType, SecurityNotificationPreference::EVENT_TYPES, true), 404);

        $data = $request->validate([
            'sms_enabled' => ['required', 'boolean'],
            'email_enabled' => ['required', 'boolean'],
        ]);

        $user = $request->user();

        SecurityNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'event_type' => $eventType],
            $data,
        );

        $this->logger->log($user, SecurityEvent::NOTIFICATION_PREFERENCE_CHANGED, 'Notification preference for '.SecurityNotificationPreference::LABELS[$eventType].' was updated.', $request);

        return back()->with('success', 'Notification preference saved.');
    }
}
