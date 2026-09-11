<?php

namespace Tests\Feature\Api;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Mobile forgot-password (via emailed OTP, not a browser reset link),
 * change-password, and profile update — none of which had a mobile
 * endpoint before this.
 */
class MobileAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeTechnician(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'Technician',
            'status' => User::STATUS_ACTIVE,
            'password' => Hash::make('correct-password'),
            ...$attributes,
        ]);
    }

    // ---------------------------------------------------------- forgot/reset

    public function test_forgot_password_issues_an_otp_by_email(): void
    {
        Notification::fake();
        $user = $this->makeTechnician();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $n) {
            return $n->purpose === 'password_reset';
        });
    }

    public function test_forgot_password_reports_success_even_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Notification::assertNothingSent();
    }

    public function test_reset_password_with_the_right_code_changes_the_password_and_revokes_tokens(): void
    {
        Notification::fake();
        $user = $this->makeTechnician();
        $user->createToken('old-device');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $code = null;
        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'type' => SecurityEvent::PASSWORD_CHANGED]);
    }

    public function test_reset_password_with_the_wrong_code_is_rejected(): void
    {
        Notification::fake();
        $user = $this->makeTechnician();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'code' => '000000',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422)->assertJsonPath('success', false);

        $this->assertTrue(Hash::check('correct-password', $user->fresh()->password));
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        Notification::fake();
        $user = $this->makeTechnician();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

        $code = null;
        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        $payload = [
            'email' => $user->email,
            'code' => $code,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
    }

    // ------------------------------------------------------------ change pw

    public function test_a_signed_in_technician_can_change_their_password(): void
    {
        $user = $this->makeTechnician();
        $currentToken = $user->createToken('current-device')->plainTextToken;
        $otherToken = $user->createToken('other-device')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));
        // This request's own token survives; every other one is revoked.
        $this->assertNotNull(PersonalAccessToken::findToken($currentToken));
        $this->assertNull(PersonalAccessToken::findToken($otherToken));
    }

    public function test_changing_password_with_the_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeTechnician();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'totally-wrong',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertTrue(Hash::check('correct-password', $user->fresh()->password));
    }

    public function test_a_pending_technician_can_still_change_their_password(): void
    {
        $user = $this->makeTechnician(['status' => User::STATUS_PENDING_APPROVAL]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertOk();
    }

    // ------------------------------------------------------------- profile

    public function test_a_technician_can_update_their_own_name(): void
    {
        $user = $this->makeTechnician(['name' => 'Old Name']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/profile', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_profile_update_never_changes_email_or_phone(): void
    {
        $user = $this->makeTechnician(['phone' => '(212) 555-0100']);
        $token = $user->createToken('test')->plainTextToken;
        $originalEmail = $user->email;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/profile', [
                'name' => 'New Name',
                'email' => 'attacker@example.com',
                'phone' => '(999) 999-9999',
            ])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame('(212) 555-0100', $fresh->phone);
    }

    public function test_a_pending_technician_can_still_update_their_profile(): void
    {
        $user = $this->makeTechnician(['status' => User::STATUS_PENDING_APPROVAL]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/profile', ['name' => 'New Name'])
            ->assertOk();
    }
}
