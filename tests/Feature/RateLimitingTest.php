<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSecuritySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The rate limiters registered in `AppServiceProvider` — gaps found during
 * the Phase 10 audit that had no throttle at all: a six-digit OTP with no
 * attempt counter, and every mobile API write reachable an unlimited
 * number of times by any valid token.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_wrong_two_factor_codes_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);
        UserSecuritySetting::forUser($user)->update(['two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/two-factor/verify', ['email' => $user->email, 'code' => '000000']);
        }

        $this->postJson('/api/v1/auth/two-factor/verify', ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(429);
    }

    public function test_the_mobile_api_group_carries_a_general_throttle(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $token = $user->createToken('test')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        for ($i = 0; $i < 120; $i++) {
            $this->withHeaders($headers)->getJson('/api/v1/auth/me');
        }

        $this->withHeaders($headers)->getJson('/api/v1/auth/me')->assertStatus(429);
    }

    public function test_repeated_password_reset_requests_are_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $user->email]);
        }

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }
}
