<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Services\Security\OtpChallengeService;
use App\Services\Security\SecurityEventLogger;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Security Settings for the app — mobile's counterpart to web's own
 * `SecuritySettingsController`, calling the same services so the two screens
 * never disagree. Password and the two-factor switch keep their existing
 * `auth/*` endpoints (`AuthController`); this adds what the screen reads, the
 * recovery codes, the email change, and the activity notifications.
 *
 * The two-factor state is the app's own switch (`CHANNEL_APP`) — the one the
 * mobile sign-in actually checks — exactly as `auth/two-factor/*` toggles it.
 */
class SecurityController extends Controller
{
    use ApiResponses;

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly OtpChallengeService $otp,
        private readonly SecurityEventLogger $logger,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->state($request->user()));
    }

    /** A fresh set of recovery codes, shown once — web's `regenerateRecoveryCodes()`. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'That password is incorrect.']);
        }

        return $this->ok([
            'recoveryCodes' => $this->twoFactor->generateRecoveryCodes($request->user(), $request),
        ], 'New recovery codes were generated.');
    }

    /** Emails a code to the new address — web's `sendEmailChallenge()`. */
    public function sendEmailChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
        ]);

        $this->otp->issue($request->user(), 'change_email', $data['email']);

        return $this->ok(null, 'A verification code was sent to the new address.');
    }

    /** Confirms that code and switches the address — web's `confirmEmail()`. */
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        // Read before verifying — `verify()` clears the entry `target()` reads.
        $newEmail = $this->otp->target($user, 'change_email');

        if (! $this->otp->verify($user, 'change_email', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $user->update(['email' => $newEmail, 'email_verified_at' => now()]);

        $this->logger->log($user, SecurityEvent::PROFILE_UPDATED, 'Email address was changed from the mobile app.', $request);

        return $this->ok($this->state($user->fresh()), 'Your email address was updated.');
    }

    /** One activity notification on or off — web's `updateNotificationPreference()`. */
    public function updateNotificationPreference(Request $request, string $eventType): JsonResponse
    {
        abort_unless(in_array($eventType, SecurityNotificationPreference::EVENT_TYPES, true), 404);

        $data = $request->validate(['email_enabled' => ['required', 'boolean']]);
        $user = $request->user();

        SecurityNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'event_type' => $eventType],
            $data,
        );

        $this->logger->log($user, SecurityEvent::NOTIFICATION_PREFERENCE_CHANGED, 'Notification preference for '.SecurityNotificationPreference::LABELS[$eventType].' was updated from the mobile app.', $request);

        return $this->ok($this->state($user), 'Notification preference saved.');
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        $settings = UserSecuritySetting::forUser($user);

        return [
            'twoFactor' => [
                'enabled' => $settings->app_two_factor_enabled,
                'confirmedAt' => $settings->app_two_factor_confirmed_at?->toISOString(),
                'recoveryCodesRemaining' => $user->recoveryCodes()->whereNull('used_at')->count(),
            ],
            'maskedEmail' => $user->maskedEmail(),
            'notificationPreferences' => SecurityNotificationPreference::allForUser($user)
                ->map(fn (SecurityNotificationPreference $preference) => [
                    'eventType' => $preference->event_type,
                    'label' => SecurityNotificationPreference::LABELS[$preference->event_type],
                    'emailEnabled' => (bool) $preference->email_enabled,
                ])->values()->all(),
        ];
    }
}
