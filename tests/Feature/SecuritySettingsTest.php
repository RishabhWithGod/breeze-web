<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\SecurityNotificationPreference;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use App\Models\UserSecuritySetting;
use App\Notifications\SecurityOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Security Settings: 2FA only ever becomes Enabled after a real OTP
 * round-trip, the audit log is exactly what `security_events` holds, and
 * every action is scoped to the authenticated user alone.
 */
class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_two_factor_defaults_to_disabled_with_no_fake_state(): void
    {
        $this->actingAs($this->user)->get('/security')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('SecuritySettings')
                ->where('twoFactor.enabled', false)
                ->where('smsConfigured', false)
                ->where('auditLog.data', []));
    }

    public function test_enabling_two_factor_requires_a_real_verified_otp_code(): void
    {
        Notification::fake();

        $this->actingAs($this->user)->post('/security/2fa/challenge')->assertRedirect();

        $code = $this->capturedOtpCode();

        $this->actingAs($this->user)->post('/security/2fa/confirm', ['code' => $code])->assertSessionHasNoErrors();

        $settings = UserSecuritySetting::forUser($this->user);
        $this->assertTrue($settings->two_factor_enabled);
        $this->assertNotNull($settings->two_factor_confirmed_at);
        $this->assertSame(8, TwoFactorRecoveryCode::where('user_id', $this->user->id)->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $this->user->id, 'type' => SecurityEvent::TWO_FACTOR_ENABLED]);
    }

    public function test_an_invalid_otp_code_does_not_enable_two_factor(): void
    {
        Notification::fake();

        $this->actingAs($this->user)->post('/security/2fa/challenge')->assertRedirect();

        $this->actingAs($this->user)->post('/security/2fa/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse(UserSecuritySetting::forUser($this->user)->two_factor_enabled);
        $this->assertDatabaseHas('security_events', ['user_id' => $this->user->id, 'type' => SecurityEvent::TWO_FACTOR_VERIFICATION_FAILED]);
    }

    public function test_disabling_two_factor_requires_the_current_password(): void
    {
        $this->enableTwoFactorFor($this->user);

        $this->actingAs($this->user)->post('/security/2fa/disable', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue(UserSecuritySetting::forUser($this->user)->two_factor_enabled);

        $this->actingAs($this->user)->post('/security/2fa/disable', ['current_password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(UserSecuritySetting::forUser($this->user)->fresh()->two_factor_enabled);
        $this->assertSame(0, TwoFactorRecoveryCode::where('user_id', $this->user->id)->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $this->user->id, 'type' => SecurityEvent::TWO_FACTOR_DISABLED]);
    }

    public function test_login_requires_the_two_factor_code_when_enabled(): void
    {
        $this->enableTwoFactorFor($this->user);
        Notification::fake();
        Auth::guard('web')->logout();

        $this->post('/login', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect('/two-factor-challenge');

        $this->assertGuest();

        $code = $this->capturedOtpCode();

        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/home');

        $this->assertAuthenticatedAs($this->user);
        $this->assertDatabaseHas('security_events', ['user_id' => $this->user->id, 'type' => SecurityEvent::LOGIN_SUCCESS]);
    }

    public function test_a_wrong_two_factor_code_at_login_is_rejected(): void
    {
        $this->enableTwoFactorFor($this->user);
        Notification::fake();
        Auth::guard('web')->logout();

        $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_recovery_code_signs_in_once_and_is_then_rejected(): void
    {
        Notification::fake();
        $this->actingAs($this->user)->post('/security/2fa/challenge');
        $code = $this->capturedOtpCode();
        $this->actingAs($this->user)->post('/security/2fa/confirm', ['code' => $code]);

        $recoveryCode = session('recoveryCodes')[0];

        Auth::guard('web')->logout();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertRedirect('/home');
        $this->assertAuthenticatedAs($this->user);

        Auth::guard('web')->logout();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_password_change_logs_an_event_and_invalidates_other_sessions(): void
    {
        DB::table('sessions')->insert([
            'id' => 'other-session-id',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('x'),
            'last_activity' => time(),
        ]);

        $this->actingAs($this->user)->put('/security/password', [
            'current_password' => 'password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', $this->user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
        $this->assertDatabaseHas('security_events', ['user_id' => $this->user->id, 'type' => SecurityEvent::PASSWORD_CHANGED]);
    }

    public function test_notification_preferences_persist_per_user(): void
    {
        $this->actingAs($this->user)->put('/security/notifications/login_attempt', [
            'sms_enabled' => false,
            'email_enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('security_notification_preferences', [
            'user_id' => $this->user->id,
            'event_type' => SecurityNotificationPreference::LOGIN_ATTEMPT,
            'email_enabled' => true,
        ]);
    }

    public function test_a_user_only_ever_sees_their_own_security_settings_and_audit_log(): void
    {
        $other = User::factory()->create();
        $this->enableTwoFactorFor($other);

        $this->actingAs($this->user)->get('/security')
            ->assertInertia(fn (Assert $page) => $page
                ->where('twoFactor.enabled', false)
                ->where('auditLog.data', []));
    }

    public function test_a_failed_login_is_logged_with_the_real_request_ip(): void
    {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $event = SecurityEvent::where('user_id', $this->user->id)->where('type', SecurityEvent::LOGIN_FAILED)->first();
        $this->assertNotNull($event);
        $this->assertNotNull($event->ip_address);
    }

    private function enableTwoFactorFor(User $user): void
    {
        Notification::fake();
        $this->actingAs($user)->post('/security/2fa/challenge');
        $code = $this->capturedOtpCode();
        $this->actingAs($user)->post('/security/2fa/confirm', ['code' => $code]);
        Notification::fake();
    }

    private function capturedOtpCode(): string
    {
        $code = null;

        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        if ($code === null) {
            $this->fail('No OTP code was captured.');
        }

        return $code;
    }
}
