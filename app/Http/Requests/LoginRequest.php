<?php

namespace App\Http\Requests;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Services\Security\SecurityEventLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Verify credentials, throttling repeated failures, and log a real
     * security event either way. Returns true when the account has 2FA
     * enabled — the caller must not log the session in yet, only start the
     * two-factor challenge.
     *
     * @throws ValidationException
     */
    public function authenticate(): bool
    {
        $this->ensureIsNotRateLimited();

        $email = Str::lower($this->string('email')->toString());
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check((string) $this->input('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            app(SecurityEventLogger::class)->log(
                $user,
                SecurityEvent::LOGIN_FAILED,
                $user ? 'A sign-in attempt failed.' : "A sign-in attempt failed for {$email} (no matching account).",
                $this,
            );

            throw ValidationException::withMessages([
                // Mirrors the prototype's copy, and still avoids confirming
                // whether the address exists.
                'email' => 'That email and password combination does not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return UserSecuritySetting::forUser($user)->two_factor_enabled;
    }

    /** @throws ValidationException */
    protected function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => "Too many sign-in attempts. Try again in {$seconds} seconds.",
            ]);
        }
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
