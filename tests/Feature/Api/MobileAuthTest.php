<?php

namespace Tests\Feature\Api;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Notifications\SecurityOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mobile authentication.
 *
 * The one rule worth pinning above everything else: a 2FA-protected account
 * must not be able to sign in from the mobile app with a password alone —
 * `login()` reuses the exact same `LoginRequest::authenticate()` the web
 * login route calls, so this is really a test that the reuse is real, not
 * a parallel check that happens to agree with it today.
 */
class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeElectrician(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'Electrician',
            'password' => Hash::make('correct-password'),
            ...$attributes,
        ]);
    }

    public function test_correct_credentials_return_a_bearer_token(): void
    {
        $user = $this->makeElectrician();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => SecurityEvent::LOGIN_SUCCESS]);
    }

    public function test_the_response_envelope_is_consistent_on_success(): void
    {
        $user = $this->makeElectrician();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertJson(fn ($json) => $json->has('success')->has('message')->has('data')->etc());
    }

    public function test_wrong_password_is_rejected_with_422_not_200(): void
    {
        $user = $this->makeElectrician();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => SecurityEvent::LOGIN_FAILED]);
    }

    public function test_repeated_failures_are_rate_limited(): void
    {
        $user = $this->makeElectrician();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_web_two_factor_does_not_ask_the_app_for_a_code_and_the_reverse(): void
    {
        $user = $this->makeElectrician();
        UserSecuritySetting::forUser($user)->update(['two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonMissingPath('data.requiresTwoFactor')
            ->assertJsonPath('data.twoFactorEnabled', false);
    }

    public function test_a_user_can_turn_app_two_factor_on_and_off_without_touching_the_web_setting(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $user = $this->makeElectrician();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->postJson('/api/v1/auth/two-factor/send', [], $headers)->assertOk();

        // A wrong code does not turn it on; the right one does.
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'], $headers)->assertStatus(422);
        $this->assertFalse(UserSecuritySetting::forUser($user)->fresh()->app_two_factor_enabled);

        \Illuminate\Support\Facades\Cache::put(
            "security-otp:enable_2fa_app:{$user->id}",
            ['hash' => \Illuminate\Support\Facades\Hash::make('123456'), 'target' => null],
            now()->addMinutes(10),
        );
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => '123456'], $headers)
            ->assertOk()
            ->assertJsonPath('data.twoFactorEnabled', true)
            ->assertJsonCount(8, 'data.recoveryCodes');

        $settings = UserSecuritySetting::forUser($user)->fresh();
        $this->assertTrue($settings->app_two_factor_enabled);
        $this->assertFalse($settings->two_factor_enabled, 'the web setting is untouched');

        $this->postJson('/api/v1/auth/two-factor/disable', ['current_password' => 'wrong'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor/disable', ['current_password' => 'correct-password'], $headers)
            ->assertOk()->assertJsonPath('data.twoFactorEnabled', false);
        $this->assertFalse(UserSecuritySetting::forUser($user)->fresh()->app_two_factor_enabled);
    }

    public function test_a_two_factor_enabled_account_does_not_receive_a_token_from_password_alone(): void
    {
        $user = $this->makeElectrician();
        UserSecuritySetting::forUser($user)->update(['app_two_factor_enabled' => true]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()->assertJsonPath('data.requiresTwoFactor', true);
        $this->assertArrayNotHasKey('token', $response->json('data'));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_completing_the_two_factor_challenge_with_the_right_code_issues_a_token(): void
    {
        Notification::fake();

        $user = $this->makeElectrician();
        UserSecuritySetting::forUser($user)->update(['app_two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password']);

        // The OTP itself is never recoverable from the cache (only its
        // hash is stored) — read the real code the same way the
        // electrician would, from the notification actually sent.
        $code = null;
        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/v1/auth/two-factor/verify', ['email' => $user->email, 'code' => $code])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_the_wrong_two_factor_code_is_rejected(): void
    {
        $user = $this->makeElectrician();
        UserSecuritySetting::forUser($user)->update(['app_two_factor_enabled' => true]);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password']);

        $this->postJson('/api/v1/auth/two-factor/verify', ['email' => $user->email, 'code' => '000000'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_an_authenticated_endpoint_rejects_a_request_with_no_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_an_authenticated_endpoint_rejects_a_garbage_token(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->makeElectrician(['name' => 'Jordan Lane']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Jordan Lane')
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_the_token_so_it_can_no_longer_be_used(): void
    {
        $user = $this->makeElectrician();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Not a second live request: within one PHPUnit test, Laravel's
        // `RequestGuard` caches the user it resolved on the *first* call
        // for the rest of the test (real per-process request handling
        // never hits this, since a fresh guard resolves on every real
        // request) — so a follow-up `getJson()` here would misleadingly
        // still "succeed" against that cached resolution, not against a
        // fresh authentication attempt. Assert against the actual
        // mechanism Sanctum's guard itself uses to validate a bearer
        // token instead.
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_me_carries_the_account_details_the_profile_screen_shows(): void
    {
        $user = $this->makeElectrician(['name' => 'Sam Rivera', 'phone' => '+1 555 010 4242']);
        $foreman = new \App\Models\Foreman(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => 'foreman', 'licence_number' => 'EL-4821']);
        $foreman->user_id = $user->id;
        $foreman->save();

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('mobile')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.name', 'Sam Rivera')
            ->assertJsonPath('data.twoFactorEnabled', false)
            ->assertJsonPath('data.work.licenceNumber', 'EL-4821')
            ->assertJsonPath('data.work.crewRole', 'Foreman');

        // The phone number is only ever shown masked.
        $this->assertStringEndsWith('4242', (string) $this->getJson('/api/v1/auth/me')->json('data.phone'));
        $this->assertStringNotContainsString('555', (string) $this->getJson('/api/v1/auth/me')->json('data.phone'));
    }

    public function test_the_devices_signed_in_are_listed_and_the_others_can_be_signed_out(): void
    {
        $user = $this->makeElectrician();
        $user->createToken('other phone');
        $mine = $user->createToken('this phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$mine)->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$mine)->postJson('/api/v1/auth/sessions/revoke-others')
            ->assertOk()->assertJsonPath('data.signedOut', 1);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => SecurityEvent::OTHER_DEVICES_SIGNED_OUT]);
    }

    public function test_the_profile_name_can_be_changed_from_the_phone(): void
    {
        $user = $this->makeElectrician(['name' => 'Old Name']);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('mobile')->plainTextToken)
            ->putJson('/api/v1/profile', ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->name);
    }
}
