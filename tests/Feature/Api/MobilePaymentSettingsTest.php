<?php

namespace Tests\Feature\Api;

use App\Models\BillingSetting;
use App\Models\PaymentProcessor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The app's Payment Settings follows web's own rules: only managers open it,
 * Stripe only goes "active" after a real authenticated call succeeds, and
 * Stripe is the only processor it offers.
 */
class MobilePaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager', 'status' => User::STATUS_ACTIVE]);
    }

    private function api(?User $as = null): self
    {
        return $this->withToken(($as ?? $this->manager)->createToken('test')->plainTextToken);
    }

    public function test_the_screen_shows_stripe_not_connected_until_credentials_are_proven(): void
    {
        $this->api()->getJson('/api/v1/office/settings')
            ->assertOk()
            ->assertJsonPath('data.processor.key', 'stripe')
            ->assertJsonPath('data.processor.status', 'not_connected')
            ->assertJsonPath('data.processor.isConnected', false)
            ->assertJsonPath('data.processor.credentialFields.secret_key', 'Secret Key')
            ->assertJsonStructure(['data' => ['subscription' => ['plan', 'usage', 'plans'], 'billingSettings', 'paymentTermsOptions']]);

        // Only Stripe — web's page is trimmed to it.
        $this->assertSame(1, PaymentProcessor::count());
    }

    public function test_stripe_connects_tests_and_disconnects(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['object' => 'balance'], 200)]);
        $id = $this->api()->getJson('/api/v1/office/settings')->json('data.processor.id');

        $this->api()->postJson("/api/v1/office/settings/processors/{$id}/connect", ['secret_key' => 'sk_test_valid'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.isConnected', true);

        $this->api()->postJson("/api/v1/office/settings/processors/{$id}/test")
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $this->api()->deleteJson("/api/v1/office/settings/processors/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'not_connected');
    }

    public function test_bad_credentials_do_not_activate_stripe(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);
        $id = $this->api()->getJson('/api/v1/office/settings')->json('data.processor.id');

        $this->api()->postJson("/api/v1/office/settings/processors/{$id}/connect", ['secret_key' => 'sk_test_bad'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.credentials.0', 'Invalid API Key provided');

        // The real error is recorded, and it never goes active.
        $processor = PaymentProcessor::find($id);
        $this->assertNotSame('active', $processor->status);
        $this->assertFalse($processor->isConnected());
        $this->assertSame('Invalid API Key provided', $processor->last_error);
    }

    public function test_billing_defaults_are_saved(): void
    {
        $this->api()->putJson('/api/v1/office/settings/billing', [
            'auto_send_invoices' => true,
            'include_payment_instructions' => true,
            'send_payment_reminders' => false,
            'apply_late_fees_automatically' => false,
            'default_payment_terms' => 'net-30',
            'default_currency' => 'USD',
        ])->assertOk();

        $this->assertTrue((bool) BillingSetting::current()->auto_send_invoices);
        $this->assertSame('net-30', BillingSetting::current()->default_payment_terms);

        $this->api()->putJson('/api/v1/office/settings/billing', [
            'auto_send_invoices' => true, 'include_payment_instructions' => true, 'send_payment_reminders' => false,
            'apply_late_fees_automatically' => false, 'default_payment_terms' => 'whenever', 'default_currency' => 'USD',
        ])->assertUnprocessable();
    }

    public function test_the_plan_changes_and_the_history_pages(): void
    {
        $plans = array_keys(config('subscription.plans'));

        $this->api()->putJson('/api/v1/office/settings/subscription', ['plan' => $plans[0], 'billing_cycle' => 'yearly'])
            ->assertOk()
            ->assertJsonPath('data.billingCycle', 'yearly');

        $this->api()->getJson('/api/v1/office/settings/transactions')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_only_managers_may_open_it(): void
    {
        $crew = User::factory()->create(['role' => 'Journeyman', 'status' => User::STATUS_ACTIVE]);

        $this->api($crew)->getJson('/api/v1/office/settings')->assertForbidden();
        $this->api($crew)->putJson('/api/v1/office/settings/billing', [])->assertForbidden();
    }
}
