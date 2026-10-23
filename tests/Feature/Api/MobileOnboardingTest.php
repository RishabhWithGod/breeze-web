<?php

namespace Tests\Feature\Api;

use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MobileOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function newManager(array $flags = []): User
    {
        return User::factory()->create([
            'role' => 'Project Manager', 'company_id' => null,
            'needs_company_setup' => true, 'needs_terms_acceptance' => true, 'needs_payment_setup' => true,
            ...$flags,
        ]);
    }

    private function auth(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_a_new_manager_is_walked_company_then_terms_then_payment(): void
    {
        $user = $this->newManager();
        $h = $this->auth($user);

        $this->getJson('/api/v1/onboarding/status', $h)->assertOk()->assertJsonPath('data.step', 'company');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me', $h)->assertOk()->assertJsonPath('data.onboarding.step', 'company');

        // The terms come after the company.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/onboarding/terms', $h)->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/company', ['name' => 'Volt & Co'], $h)->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/company', [
            'name' => 'Volt & Co', 'business_address' => '1 Main St, Austin TX', 'primary_contact' => 'Ada',
            'phone' => '(512) 555-0142', 'email' => 'ops@volt.test',
        ], $h)->assertOk()->assertJsonPath('data.step', 'terms');
        $this->assertNotNull($user->fresh()->company_id);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/terms', ['signer_name' => 'Ada Lovelace'], $h)->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/terms', [
            'accept_terms' => true, 'accept_privacy' => true, 'signer_name' => 'Ada Lovelace',
        ], $h)->assertOk()->assertJsonPath('data.step', 'payment');
        $this->assertDatabaseHas('terms_acceptances', ['user_id' => $user->id, 'signer_name' => 'Ada Lovelace']);
    }

    public function test_payment_cannot_start_without_stripe_configured(): void
    {
        $user = $this->newManager(['needs_company_setup' => false, 'needs_terms_acceptance' => false]);
        $plan = array_key_first(config('subscription.plans'));

        $this->postJson('/api/v1/onboarding/payment', ['plan' => $plan, 'billing_cycle' => 'monthly', 'seats' => 3], $this->auth($user))
            ->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/payment/confirm', [], $this->auth($user))->assertStatus(409);
    }

    public function test_get_started_is_the_managers_and_can_only_finish_when_every_step_is_accounted_for(): void
    {
        $user = $this->newManager(['needs_company_setup' => false, 'needs_terms_acceptance' => false, 'needs_payment_setup' => false]);
        $company = CompanyProfile::create([
            'user_id' => $user->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $user->forceFill(['company_id' => $company->id])->save();
        $h = $this->auth($user->fresh());

        $page = $this->getJson('/api/v1/onboarding/get-started', $h)->assertOk();
        $this->assertSame('company', $page->json('data.checklist.steps.0.key'));
        $this->assertSame('completed', $page->json('data.checklist.steps.0.status'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/get-started/finish', [], $h)->assertStatus(409);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/get-started/skip/team', [], $h)->assertOk();
        $this->assertContains('team', $company->fresh()->onboarding_skipped);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/onboarding/get-started/skip/bogus', [], $h)->assertNotFound();

        // A technician is not a manager.
        $tech = User::factory()->create(['role' => 'Journeyman', 'company_id' => $company->id]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/onboarding/get-started', $this->auth($tech))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/onboarding/team', $this->auth($tech))->assertForbidden();
    }

    public function test_an_unknown_invitation_link_says_so(): void
    {
        $this->getJson('/api/v1/invitations/not-a-real-token')->assertOk()
            ->assertJsonPath('data.state', 'invalid');
        $this->postJson('/api/v1/invitations/not-a-real-token', ['password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd'])
            ->assertNotFound();
    }

    public function test_a_valid_invitation_can_be_read_and_accepted_and_signs_the_phone_in(): void
    {
        $manager = $this->newManager(['needs_company_setup' => false, 'needs_terms_acceptance' => false, 'needs_payment_setup' => false]);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $manager->forceFill(['company_id' => $company->id])->save();

        [$token, $hash] = \App\Models\TeamInvitation::newToken();
        $invitation = new \App\Models\TeamInvitation([
            'company_id' => $company->id, 'invited_by' => $manager->id, 'name' => 'Sam Rivera',
            'email' => 'sam@volt.test', 'role' => 'Foreman',
        ]);
        $invitation->forceFill(['token_hash' => $hash, 'sent_at' => now(), 'expires_at' => now()->addDays(7)])->save();

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.invitation.company', 'Volt & Co')
            ->assertJsonPath('data.invitation.role', 'Foreman');

        $this->postJson("/api/v1/invitations/{$token}", ['password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'nope'])
            ->assertStatus(422);

        $response = $this->postJson("/api/v1/invitations/{$token}", ['password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd']);
        $response->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role']]]);
        $this->assertSame('Foreman', $response->json('data.user.role'));
        $this->assertDatabaseHas('users', ['email' => 'sam@volt.test', 'company_id' => $company->id]);

        // Used once: the link is spent.
        $this->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.state', 'accepted');
    }

    public function test_only_the_owner_corrects_the_company_details(): void
    {
        $owner = $this->newManager(['needs_company_setup' => false, 'needs_terms_acceptance' => false, 'needs_payment_setup' => false]);
        $company = CompanyProfile::create([
            'user_id' => $owner->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $owner->forceFill(['company_id' => $company->id])->save();
        $other = User::factory()->create(['role' => 'Project Manager', 'company_id' => $company->id]);

        $this->getJson('/api/v1/office/settings/company', $this->auth($owner->fresh()))
            ->assertOk()->assertJsonPath('data.company.name', 'Volt & Co');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/office/settings/company', [
            'name' => 'Volt Electric', 'business_address' => '2 Main St', 'primary_contact' => 'Ada',
            'phone' => '(512) 555-0199', 'email' => 'new@x.test', 'timezone' => 'America/New_York',
        ], $this->auth($owner->fresh()))->assertOk();
        $this->assertSame('Volt Electric', $company->fresh()->name);
        $this->assertSame('America/New_York', $company->fresh()->timezone);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/office/settings/company', ['name' => 'Hijack'], $this->auth($other))->assertForbidden();
    }
}
