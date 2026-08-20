<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Payment Settings: processors only ever go "active" after a real (faked
 * in tests) authenticated call to the processor succeeds, payment methods
 * only exist against a connected processor, billing settings persist, and
 * every mutation is manager-only.
 */
class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $electrician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->electrician = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_the_index_page_seeds_all_three_processors_as_not_connected(): void
    {
        $this->actingAs($this->manager)->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('PaymentSettings')
                ->has('processors', 3)
                ->where('processors.0.status', 'not_connected')
                ->where('processors.1.status', 'not_connected')
                ->where('processors.2.status', 'not_connected'));

        $this->assertDatabaseCount('payment_processors', 3);
    }

    public function test_connecting_stripe_with_valid_credentials_makes_a_real_test_call_and_activates_it(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['object' => 'balance'], 200)]);

        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);

        $this->actingAs($this->manager)
            ->post("/settings/payment/processors/{$processor->id}/connect", ['secret_key' => 'sk_test_valid'])
            ->assertRedirect();

        $processor->refresh();
        $this->assertSame(PaymentProcessor::STATUS_ACTIVE, $processor->status);
        $this->assertNotNull($processor->connected_at);
        // The credential is encrypted, never stored or returned as plaintext.
        $this->assertNotSame('sk_test_valid', $processor->getRawOriginal('credentials'));
        $this->assertSame('sk_test_valid', $processor->credentials['secret_key']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.stripe.com'));
    }

    public function test_connecting_with_invalid_credentials_records_the_real_error_and_does_not_activate(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);

        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);

        $this->actingAs($this->manager)
            ->post("/settings/payment/processors/{$processor->id}/connect", ['secret_key' => 'sk_bad'])
            ->assertSessionHasErrors('credentials');

        $processor->refresh();
        $this->assertSame(PaymentProcessor::STATUS_ERROR, $processor->status);
        $this->assertSame('Invalid API Key provided', $processor->last_error);
    }

    public function test_disconnecting_a_processor_clears_credentials_and_notifies_other_managers(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['object' => 'balance'], 200)]);
        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);
        $processor->update(['status' => PaymentProcessor::STATUS_ACTIVE, 'credentials' => ['secret_key' => 'sk_x'], 'connected_at' => now()]);

        $otherManager = User::factory()->create(['role' => 'Admin']);

        $this->actingAs($this->manager)
            ->delete("/settings/payment/processors/{$processor->id}")
            ->assertRedirect();

        $processor->refresh();
        $this->assertSame(PaymentProcessor::STATUS_NOT_CONNECTED, $processor->status);
        $this->assertNull($processor->credentials);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $otherManager->id,
            'type' => 'payment-processor-disconnected',
        ]);
        // The manager who disconnected it doesn't notify themselves.
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $this->manager->id,
            'type' => 'payment-processor-disconnected',
        ]);
    }

    public function test_a_payment_method_cannot_be_added_without_a_connected_processor(): void
    {
        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);

        $this->actingAs($this->manager)->post('/settings/payment/methods', [
            'payment_processor_id' => $processor->id,
            'brand' => 'Visa',
            'last_four' => '4242',
            'exp_month' => 9,
            'exp_year' => now()->year + 1,
        ])->assertSessionHasErrors('payment_processor_id');

        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_the_first_payment_method_added_becomes_the_default_automatically(): void
    {
        $processor = $this->connectedStripe();

        $this->actingAs($this->manager)->post('/settings/payment/methods', [
            'payment_processor_id' => $processor->id,
            'brand' => 'Visa',
            'last_four' => '4242',
            'exp_month' => 9,
            'exp_year' => now()->year + 1,
        ])->assertRedirect();

        $method = PaymentMethod::first();
        $this->assertNotNull($method);
        $this->assertTrue($method->is_default);
    }

    public function test_setting_a_new_default_unsets_the_previous_one(): void
    {
        $processor = $this->connectedStripe();
        $first = PaymentMethod::create(['payment_processor_id' => $processor->id, 'brand' => 'Visa', 'last_four' => '4242', 'exp_month' => 9, 'exp_year' => now()->year + 1, 'is_default' => true]);
        $second = PaymentMethod::create(['payment_processor_id' => $processor->id, 'brand' => 'Mastercard', 'last_four' => '8790', 'exp_month' => 9, 'exp_year' => now()->year + 1, 'is_default' => false]);

        $this->actingAs($this->manager)
            ->patch("/settings/payment/methods/{$second->id}/default")
            ->assertRedirect();

        $this->assertFalse($first->refresh()->is_default);
        $this->assertTrue($second->refresh()->is_default);
    }

    public function test_deleting_the_default_method_promotes_another_one(): void
    {
        $processor = $this->connectedStripe();
        $first = PaymentMethod::create(['payment_processor_id' => $processor->id, 'brand' => 'Visa', 'last_four' => '4242', 'exp_month' => 9, 'exp_year' => now()->year + 1, 'is_default' => false]);
        $second = PaymentMethod::create(['payment_processor_id' => $processor->id, 'brand' => 'Mastercard', 'last_four' => '8790', 'exp_month' => 9, 'exp_year' => now()->year + 1, 'is_default' => true]);

        $this->actingAs($this->manager)
            ->delete("/settings/payment/methods/{$second->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('payment_methods', ['id' => $second->id]);
        $this->assertTrue($first->refresh()->is_default);
    }

    public function test_billing_settings_persist_and_survive_a_fresh_load(): void
    {
        $this->actingAs($this->manager)->put('/settings/payment/billing', [
            'auto_send_invoices' => true,
            'include_payment_instructions' => true,
            'send_payment_reminders' => false,
            'apply_late_fees_automatically' => false,
            'default_payment_terms' => 'net-30',
            'default_currency' => 'CAD',
        ])->assertRedirect();

        $settings = BillingSetting::current();
        $this->assertTrue($settings->auto_send_invoices);
        $this->assertSame('net-30', $settings->default_payment_terms);
        $this->assertSame('CAD', $settings->default_currency);

        $this->actingAs($this->manager)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('billingSettings.defaultPaymentTerms', 'net-30')
                ->where('billingSettings.defaultCurrency', 'CAD'));
    }

    public function test_a_non_manager_cannot_view_or_mutate_payment_settings(): void
    {
        $this->actingAs($this->electrician)->get('/settings')->assertForbidden();

        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);
        $this->actingAs($this->electrician)
            ->post("/settings/payment/processors/{$processor->id}/connect", ['secret_key' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->electrician)
            ->put('/settings/payment/billing', ['auto_send_invoices' => true, 'include_payment_instructions' => false, 'send_payment_reminders' => false, 'apply_late_fees_automatically' => false, 'default_payment_terms' => 'net-30', 'default_currency' => 'USD'])
            ->assertForbidden();
    }

    public function test_marking_an_invoice_paid_records_a_real_transaction_that_appears_in_payment_history(): void
    {
        $this->actingAs($this->manager)->post('/invoices', [
            'client' => 'Apex Construction',
            'invoice_date' => now()->toDateString(),
            'due_date' => null,
            'tax_pct' => 0,
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->first();
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/items", [
            'description' => 'Panel install', 'quantity' => 1, 'unit_price' => 750,
        ]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/send");
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/mark-paid")->assertRedirect();

        $transaction = PaymentTransaction::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($transaction);
        $this->assertSame(PaymentTransaction::STATUS_COMPLETED, $transaction->status);
        $this->assertEquals(750.0, (float) $transaction->amount);
        $this->assertNull($transaction->payment_processor_id, 'manually recorded, not processor-attributed');

        $this->actingAs($this->manager)->get('/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 1)
                ->where('transactions.data.0.description', "Invoice {$invoice->invoice_number} marked paid")
                ->where('transactions.data.0.processorName', 'Manual'));
    }

    private function connectedStripe(): PaymentProcessor
    {
        $processor = PaymentProcessor::firstOrCreate(['key' => 'stripe'], ['display_name' => 'Stripe']);
        $processor->update(['status' => PaymentProcessor::STATUS_ACTIVE, 'credentials' => ['secret_key' => 'sk_x'], 'connected_at' => now()]);

        return $processor;
    }
}
