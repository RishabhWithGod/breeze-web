<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The app's sign-up is web's sign-up: three fields, then first-run setup. */
class MobileSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_creates_an_account_that_still_needs_company_setup(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', [
            'name' => 'Alex Morgan',
            'email' => 'Alex@Example.com',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
        $response->assertJsonPath('data.user.status', 'active');
        $response->assertJsonPath('data.user.onboarding.step', 'company');

        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $this->assertTrue((bool) $user->needs_company_setup);
        $this->assertTrue((bool) $user->needs_terms_acceptance);
        $this->assertTrue((bool) $user->needs_payment_setup);
    }

    public function test_signup_rejects_a_taken_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/signup', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertUnprocessable();
    }
}
