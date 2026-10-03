<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Models\UserSecuritySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Real 2FA enrollment: a toggle never flips `two_factor_enabled` by itself —
 * only `confirmEnable()` does, and only after a genuine OTP round-trip.
 */
class TwoFactorService
{
    public const ENABLE_PURPOSE = 'enable_2fa';

    /** The mobile app enrolls on its own code, so a web enrollment code cannot confirm it (or the reverse). */
    public const APP_ENABLE_PURPOSE = 'enable_2fa_app';

    public function __construct(
        private readonly OtpChallengeService $otp,
        private readonly SecurityEventLogger $logger,
    ) {}

    private function purposeFor(string $channel): string
    {
        return $channel === UserSecuritySetting::CHANNEL_APP ? self::APP_ENABLE_PURPOSE : self::ENABLE_PURPOSE;
    }

    public function sendEnableChallenge(User $user, string $channel = UserSecuritySetting::CHANNEL_WEB): void
    {
        $settings = UserSecuritySetting::forUser($user);

        if ($settings->two_factor_method === UserSecuritySetting::METHOD_SMS) {
            throw ValidationException::withMessages([
                'method' => 'SMS authentication is not configured. Choose Email to continue.',
            ]);
        }

        $this->otp->issue($user, $this->purposeFor($channel));
    }

    /** @return array{success: bool, recoveryCodes?: list<string>} */
    public function confirmEnable(User $user, string $code, Request $request, string $channel = UserSecuritySetting::CHANNEL_WEB): array
    {
        if (! $this->otp->verify($user, $this->purposeFor($channel), $code)) {
            $this->logger->log($user, SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED, 'A two-factor enrollment code failed to verify.', $request);

            return ['success' => false];
        }

        $settings = UserSecuritySetting::forUser($user);
        $app = $channel === UserSecuritySetting::CHANNEL_APP;
        $settings->update($app
            ? ['app_two_factor_enabled' => true, 'app_two_factor_confirmed_at' => now()]
            : ['two_factor_enabled' => true, 'two_factor_confirmed_at' => now()]);

        // Recovery codes are shared by both channels; only mint a new set
        // when the person has none left to use, so enabling the second
        // channel does not invalidate the codes they already saved.
        $hasCodes = TwoFactorRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->exists();
        $codes = $hasCodes ? [] : $this->generateRecoveryCodes($user);
        $this->logger->log($user, SecurityEvent::TWO_FACTOR_ENABLED, 'Two-factor authentication was turned on'.($app ? ' for the app.' : '.'), $request);

        return ['success' => true, 'recoveryCodes' => $codes];
    }

    public function disable(User $user, string $currentPassword, Request $request, string $channel = UserSecuritySetting::CHANNEL_WEB): bool
    {
        if (! Hash::check($currentPassword, $user->password)) {
            return false;
        }

        $app = $channel === UserSecuritySetting::CHANNEL_APP;
        $settings = UserSecuritySetting::forUser($user);
        $settings->update($app
            ? ['app_two_factor_enabled' => false, 'app_two_factor_confirmed_at' => null]
            : ['two_factor_enabled' => false, 'two_factor_confirmed_at' => null]);

        // The recovery codes belong to whichever channel is still on.
        if (! $settings->two_factor_enabled && ! $settings->app_two_factor_enabled) {
            TwoFactorRecoveryCode::query()->where('user_id', $user->id)->delete();
        }

        $this->logger->log($user, SecurityEvent::TWO_FACTOR_DISABLED, 'Two-factor authentication was turned off'.($app ? ' for the app.' : '.'), $request);

        return true;
    }

    /** @return list<string> plaintext codes, shown to the user exactly once */
    public function generateRecoveryCodes(User $user, ?Request $request = null): array
    {
        TwoFactorRecoveryCode::query()->where('user_id', $user->id)->delete();

        $codes = [];

        for ($i = 0; $i < 8; $i++) {
            $code = Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
            $codes[] = $code;

            TwoFactorRecoveryCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        if ($request) {
            $this->logger->log($user, SecurityEvent::RECOVERY_CODES_GENERATED, 'Two-factor recovery codes were regenerated.', $request);
        }

        return $codes;
    }

    public function verifyRecoveryCode(User $user, string $code, Request $request): bool
    {
        $candidates = TwoFactorRecoveryCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($code, $candidate->code_hash)) {
                $candidate->update(['used_at' => now()]);
                $this->logger->log($user, SecurityEvent::RECOVERY_CODE_USED, 'A two-factor recovery code was used to sign in.', $request);

                return true;
            }
        }

        return false;
    }

    public function setMethod(User $user, string $method, Request $request): void
    {
        $settings = UserSecuritySetting::forUser($user);

        if ($settings->two_factor_method === $method) {
            return;
        }

        $settings->update(['two_factor_method' => $method]);
        $this->logger->log($user, SecurityEvent::AUTH_METHOD_CHANGED, "Authentication method changed to {$method}.", $request);
    }
}
