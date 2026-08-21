<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * `LoginRequest` already rate-limits credential attempts itself (5 per
     * email+ip) — these cover the gaps that had no limiter at all: a
     * six-digit OTP has only a million combinations and no attempt counter
     * of its own, and every mobile API write was reachable an unlimited
     * number of times by any valid token.
     */
    private function registerRateLimiters(): void
    {
        // The pending user id (set by a real password check, before the
        // session is logged in) is the right key when it exists; the
        // mobile verify endpoint has no session at all (the `api` group
        // carries no `StartSession` middleware, so `$request->session()`
        // would throw rather than return empty), so it falls back to the
        // email in the request, then the IP as a last resort.
        RateLimiter::for('two-factor', function (Request $request) {
            $key = ($request->hasSession() ? $request->session()->get('two_factor.user_id') : null)
                ?? (filled($request->input('email')) ? Str::lower($request->string('email')->toString()) : null)
                ?? $request->ip();

            return Limit::perMinutes(10, 5)->by("two-factor:{$key}");
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $key = Str::lower((string) $request->input('email')).'|'.$request->ip();

            return Limit::perMinutes(60, 5)->by("password-reset:{$key}");
        });

        // Generous enough not to interfere with normal app/mobile use —
        // this exists to bound abuse of an already-authenticated token,
        // not to rate-limit legitimate traffic.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by('api:'.($request->user()?->id ?? $request->ip()));
        });

        // AI takeoff processing calls a real, costed external service —
        // upload, start, retry and restart all end up dispatching that
        // call, so all four share one strict per-user budget.
        RateLimiter::for('ai-processing', function (Request $request) {
            return Limit::perMinute(5)->by('ai-processing:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
