<?php

namespace Tests\Feature\Api;

use App\Models\SecurityNotificationPreference;
use App\Models\User;
use App\Notifications\SecurityOtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The app's Security Settings screen reads and writes the same state web's
 * own `SecuritySettingsController` does.
 */
class MobileSecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'dana@example.com',
            'status' => User::STATUS_ACTIVE,
            'password' => Hash::make('correct-password'),
        ]);
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_the_screen_reads_the_real_security_state(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/security')
            ->assertOk()
            ->assertJsonPath('data.twoFactor.enabled', false)
            ->assertJsonPath('data.twoFactor.recoveryCodesRemaining', 0)
            ->assertJsonPath('data.maskedEmail', $this->user->maskedEmail())
            ->assertJsonCount(count(SecurityNotificationPreference::EVENT_TYPES), 'data.notificationPreferences');
    }

    public function test_a_notification_preference_is_saved(): void
    {
        $type = SecurityNotificationPreference::EVENT_TYPES[0];

        $this->withToken($this->token)
            ->putJson("/api/v1/security/notifications/{$type}", ['email_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.notificationPreferences.0.emailEnabled', false);

        $this->assertFalse((bool) SecurityNotificationPreference::where('user_id', $this->user->id)
            ->where('event_type', $type)->value('email_enabled'));
    }

    public function test_recovery_codes_need_the_current_password(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/security/recovery-codes', ['current_password' => 'wrong'])
            ->assertUnprocessable();

        $codes = $this->withToken($this->token)
            ->postJson('/api/v1/security/recovery-codes', ['current_password' => 'correct-password'])
            ->assertOk()
            ->json('data.recoveryCodes');

        $this->assertNotEmpty($codes);
    }

    public function test_the_email_is_changed_only_after_the_code_checks_out(): void
    {
        Notification::fake();

        $this->withToken($this->token)
            ->postJson('/api/v1/security/email/challenge', ['email' => 'new@example.com'])
            ->assertOk();

        $code = null;
        Notification::assertSentOnDemand(SecurityOtpCode::class, function (SecurityOtpCode $n) use (&$code) {
            $code = $n->code;

            return $n->purpose === 'change_email';
        });

        $this->withToken($this->token)
            ->postJson('/api/v1/security/email/confirm', ['code' => '000000'])
            ->assertUnprocessable();
        $this->assertSame('dana@example.com', $this->user->fresh()->email);

        $this->withToken($this->token)
            ->postJson('/api/v1/security/email/confirm', ['code' => $code])
            ->assertOk();
        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }
}
