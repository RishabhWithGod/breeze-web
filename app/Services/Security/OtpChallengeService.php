<?php

namespace App\Services\Security;

use App\Models\User;
use App\Notifications\SecurityOtpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * A single, real email-delivered OTP mechanism reused for every sensitive
 * action that needs a verification step (enabling 2FA, changing email,
 * changing phone, and the login-time 2FA challenge) — one implementation,
 * not a parallel ad hoc scheme per feature.
 *
 * Only the hash of the code is ever stored, in the cache, with its own TTL —
 * never a table, never plaintext, never longer-lived than it needs to be.
 */
class OtpChallengeService
{
    private const TTL_MINUTES = 10;

    public function issue(User $user, string $purpose, ?string $target = null): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->key($user, $purpose), [
            'hash' => Hash::make($code),
            'target' => $target,
        ], now()->addMinutes(self::TTL_MINUTES));

        $recipient = $target ?? $user->email;

        Notification::route('mail', $recipient)->notifyNow(new SecurityOtpCode($code, $purpose));
    }

    public function verify(User $user, string $purpose, string $code): bool
    {
        $entry = Cache::get($this->key($user, $purpose));

        if (! $entry || ! Hash::check($code, $entry['hash'])) {
            return false;
        }

        Cache::forget($this->key($user, $purpose));

        return true;
    }

    /** The email/phone this challenge was actually sent to, if it targeted one other than the user's own email. */
    public function target(User $user, string $purpose): ?string
    {
        return Cache::get($this->key($user, $purpose))['target'] ?? null;
    }

    public function discard(User $user, string $purpose): void
    {
        Cache::forget($this->key($user, $purpose));
    }

    private function key(User $user, string $purpose): string
    {
        return "security-otp:{$purpose}:{$user->id}";
    }
}
