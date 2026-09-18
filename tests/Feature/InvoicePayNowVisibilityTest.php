<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentProcessor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pay Now only ever appears when both halves of it are real: a Stripe
 * processor this app has actually tested and connected, and a manager who is
 * allowed to collect on this particular invoice. Neither half is inferred
 * from the other — a connected Stripe with nobody allowed to collect, or a
 * manager with nothing connected to collect through, both still hide it.
 */
class InvoicePayNowVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_pay_now_is_hidden_when_no_processor_is_connected(): void
    {
        $invoice = $this->sentInvoiceFor($this->manager);

        $this->actingAs($this->manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.markPaid', true)
                ->where('stripeConnected', false));
    }

    public function test_pay_now_is_visible_once_stripe_is_actually_connected(): void
    {
        $invoice = $this->sentInvoiceFor($this->manager);
        $this->connectStripe();

        $this->actingAs($this->manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.markPaid', true)
                ->where('stripeConnected', true));
    }

    /** A processor that was connected and later disconnected is exactly the same as never having connected one. */
    public function test_pay_now_is_hidden_again_once_stripe_is_disconnected(): void
    {
        $invoice = $this->sentInvoiceFor($this->manager);
        $processor = $this->connectStripe();
        $processor->update(['status' => PaymentProcessor::STATUS_NOT_CONNECTED]);

        $this->actingAs($this->manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->where('stripeConnected', false));
    }

    /** Stripe being connected does not hand collection rights to someone the invoice policy would otherwise refuse. */
    public function test_pay_now_stays_unavailable_to_a_user_without_collection_rights(): void
    {
        $this->connectStripe();

        // Only a manager may raise an invoice at all (InvoicePolicy::create) —
        // an Electrician owning a sent one is created directly, not through
        // that gate, since it's `markPaid` this test is exercising.
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $invoice = Invoice::create([
            'user_id' => $electrician->id,
            'invoice_number' => 'INV-9001',
            'client' => 'Apex Construction',
            'invoice_date' => '2026-08-10',
            'subtotal' => 250,
            'tax_pct' => 0,
            'tax_total' => 0,
            'total' => 250,
            'status' => Invoice::STATUS_SENT,
            'sent_at' => now(),
            'created_by' => $electrician->id,
        ]);

        $this->actingAs($electrician)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.markPaid', false)
                ->where('stripeConnected', true));
    }

    /** The manual path this button sits beside is untouched by any of the above. */
    public function test_mark_as_paid_still_works_regardless_of_stripe(): void
    {
        $invoice = $this->sentInvoiceFor($this->manager);

        $this->actingAs($this->manager)
            ->post("/invoices/{$invoice->id}/mark-paid")
            ->assertSessionHasNoErrors();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** Trying to pay online with nothing connected is refused server-side too — not just hidden in the UI. */
    public function test_checkout_is_refused_server_side_when_stripe_is_not_connected(): void
    {
        $invoice = $this->sentInvoiceFor($this->manager);

        $this->actingAs($this->manager)
            ->post("/invoices/{$invoice->id}/pay")
            ->assertSessionHas('warning');

        $this->assertSame(Invoice::STATUS_SENT, $invoice->fresh()->status);
    }

    private function sentInvoiceFor(User $owner): Invoice
    {
        $client = Client::create([
            'user_id' => $owner->id,
            'name' => 'Apex Construction',
        ]);

        $this->actingAs($owner)->post('/invoices', [
            'client_id' => $client->id,
            'invoice_date' => '2026-08-10',
            'tax_pct' => 0,
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::latest('id')->first();

        $this->actingAs($owner)->post("/invoices/{$invoice->id}/items", [
            'description' => 'Service call',
            'quantity' => 1,
            'unit_price' => 250,
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post("/invoices/{$invoice->id}/send")->assertSessionHasNoErrors();

        return $invoice->refresh();
    }

    /** Exactly what `PaymentSettingsController::connectProcessor()` writes on a successful live test — not a shortcut past it. */
    private function connectStripe(): PaymentProcessor
    {
        return PaymentProcessor::create([
            'key' => PaymentProcessor::STRIPE,
            'display_name' => PaymentProcessor::DISPLAY_NAMES[PaymentProcessor::STRIPE],
            'status' => PaymentProcessor::STATUS_ACTIVE,
            'credentials' => ['secret_key' => 'sk_test_fake'],
            'connected_at' => now(),
            'last_tested_at' => now(),
            'connected_by' => $this->manager->id,
        ]);
    }
}
