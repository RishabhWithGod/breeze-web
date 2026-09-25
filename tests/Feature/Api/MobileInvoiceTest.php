<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\PaymentProcessor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile's Invoice module — full parity with web's `InvoiceController`/
 * `InvoiceDetailController`/`InvoicePaymentController` (same `Invoice`
 * model, same `InvoicePolicy`, same `EstimateInvoiceSync`/
 * `ClientDirectory`/`StripeConnector`), reshaped into JSON responses.
 */
class MobileInvoiceTest extends TestCase
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

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function makeClient(User $owner, string $name = 'Apex Construction'): Client
    {
        return Client::create(['user_id' => $owner->id, 'name' => $name]);
    }

    private function makeJob(User $owner, array $attributes = []): Job
    {
        return Job::create([
            'user_id' => $owner->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeInvoice(User $owner, Client $client, array $attributes = []): Invoice
    {
        return Invoice::create([
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'client' => $client->name,
            'invoice_number' => Invoice::nextNumber($owner),
            'invoice_date' => now()->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
            'created_by' => $owner->id,
            ...$attributes,
        ]);
    }

    // --- Index -----------------------------------------------------------

    public function test_index_only_lists_the_signed_in_managers_own_invoices(): void
    {
        $client = $this->makeClient($this->manager);
        $mine = $this->makeInvoice($this->manager, $client);
        $otherManager = User::factory()->create(['role' => 'Project Manager']);
        $other = $this->makeInvoice($otherManager, $this->makeClient($otherManager, 'Someone Else'));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson('/api/v1/invoices')
            ->assertOk();

        $ids = array_column($response->json('data.invoices'), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_index_defaults_to_newest_first(): void
    {
        $client = $this->makeClient($this->manager);
        $older = $this->makeInvoice($this->manager, $client, ['invoice_date' => '2026-01-01']);
        $newer = $this->makeInvoice($this->manager, $client, ['invoice_date' => '2026-06-01']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson('/api/v1/invoices')
            ->assertOk();

        $ids = array_values(array_intersect(array_column($response->json('data.invoices'), 'id'), [$older->id, $newer->id]));
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_index_filters_by_status(): void
    {
        $client = $this->makeClient($this->manager);
        $draft = $this->makeInvoice($this->manager, $client);
        $paid = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson('/api/v1/invoices?status=paid')
            ->assertOk();

        $ids = array_column($response->json('data.invoices'), 'id');
        $this->assertContains($paid->id, $ids);
        $this->assertNotContains($draft->id, $ids);
    }

    public function test_index_sends_summary_and_can_abilities(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'invoices', 'meta' => ['currentPage', 'lastPage', 'total'],
                    'clients', 'jobs',
                    'summary' => ['totalOutstanding', 'overdue', 'paidThisMonth', 'averageDaysToPay'],
                    'can' => ['create', 'manage'],
                ],
            ])
            ->assertJsonPath('data.can.create', true);
    }

    public function test_an_electrician_cannot_create_invoices(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->electrician))
            ->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonPath('data.can.create', false);
    }

    // --- Create options ----------------------------------------------------

    public function test_create_options_returns_next_number_clients_jobs_and_uninvoiced_estimates(): void
    {
        $client = $this->makeClient($this->manager);
        $job = $this->makeJob($this->manager, ['status' => 'completed']);
        Estimate::create([
            'user_id' => $this->manager->id, 'job_id' => $job->id, 'number' => 'EST-1001',
            'project' => 'Riverside Office Renovation',
            'client' => $client->name, 'client_id' => $client->id, 'status' => 'sent',
            'issued_on' => now(), 'amount' => 500, 'grand_total' => 500,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson('/api/v1/invoices/create-options')
            ->assertOk();

        $this->assertSame('INV-1001', $response->json('data.nextNumber'));
        $this->assertCount(1, $response->json('data.clients'));
        $this->assertCount(1, $response->json('data.estimates'));
        // Regression: `grand_total` is a `decimal:2` cast — left unmapped,
        // Eloquent serializes it as a string ("500.00"), which a strict
        // mobile JSON decoder (Dart's `num?` cast) rejects outright. A
        // whole-number float still encodes as a bare JSON int (e.g. `500`,
        // not `500.0`) — asserting non-string is what actually matters.
        $this->assertIsNotString($response->json('data.estimates.0.grand_total'));
    }

    // --- Store -------------------------------------------------------------

    public function test_a_manager_can_create_a_draft_invoice(): void
    {
        $client = $this->makeClient($this->manager);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson('/api/v1/invoices', [
                'client_id' => $client->id,
                'invoice_date' => '2026-09-23',
                'tax_pct' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.client', $client->name)
            ->assertJsonPath('data.total', 0);

        $this->assertSame('INV-1001', $response->json('data.invoiceNumber'));
    }

    public function test_creating_an_invoice_against_a_non_completed_job_fails(): void
    {
        $client = $this->makeClient($this->manager);
        $job = $this->makeJob($this->manager, ['status' => 'in-progress']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson('/api/v1/invoices', [
                'client_id' => $client->id, 'job_id' => $job->id, 'invoice_date' => '2026-09-23', 'tax_pct' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_id']);
    }

    public function test_creating_an_invoice_against_an_already_invoiced_job_fails(): void
    {
        $client = $this->makeClient($this->manager);
        $job = $this->makeJob($this->manager, ['status' => 'completed']);
        $this->makeInvoice($this->manager, $client, ['job_id' => $job->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson('/api/v1/invoices', [
                'client_id' => $client->id, 'job_id' => $job->id, 'invoice_date' => '2026-09-23', 'tax_pct' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['job_id']);
    }

    public function test_an_electrician_cannot_create_an_invoice(): void
    {
        $client = $this->makeClient($this->electrician);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->electrician))
            ->postJson('/api/v1/invoices', [
                'client_id' => $client->id, 'invoice_date' => '2026-09-23', 'tax_pct' => 0,
            ])
            ->assertStatus(403);
    }

    // --- Show / update -------------------------------------------------------

    public function test_show_returns_the_full_detail_payload(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['notes' => 'Net 14']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'invoiceNumber', 'client', 'clientId', 'jobId', 'jobName',
                    'estimateId', 'estimateNumber', 'invoiceDate', 'dueDate',
                    'subtotal', 'taxPct', 'taxTotal', 'total', 'paidAmount', 'outstanding',
                    'status', 'isEditable', 'notes', 'sentAt', 'paidAt', 'createdBy', 'createdAt',
                    'items', 'can' => ['update', 'delete', 'send', 'markPaid'], 'stripeConnected',
                ],
            ])
            ->assertJsonPath('data.notes', 'Net 14');
    }

    public function test_another_managers_invoice_is_not_visible(): void
    {
        $otherManager = User::factory()->create(['role' => 'Project Manager']);
        $invoice = $this->makeInvoice($otherManager, $this->makeClient($otherManager));

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(403);
    }

    public function test_a_manager_can_update_a_draft_invoices_header(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'client_id' => $client->id, 'invoice_date' => '2026-09-23', 'tax_pct' => 10, 'notes' => 'Updated',
            ])
            ->assertOk()
            ->assertJsonPath('data.taxPct', 10)
            ->assertJsonPath('data.notes', 'Updated');
    }

    public function test_a_paid_invoice_cannot_be_updated(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->putJson("/api/v1/invoices/{$invoice->id}", [
                'client_id' => $client->id, 'invoice_date' => '2026-09-23', 'tax_pct' => 0,
            ])
            ->assertStatus(403);
    }

    // --- Line items ----------------------------------------------------------

    public function test_adding_an_item_recalculates_totals(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['tax_pct' => 10]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/items", [
                'description' => 'Panel install', 'quantity' => 2, 'unit_price' => 150,
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(300.0, $response->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(30.0, $response->json('data.taxTotal'), 0.001);
        $this->assertEqualsWithDelta(330.0, $response->json('data.total'), 0.001);
    }

    public function test_updating_and_deleting_an_item_recalculates_totals(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);
        $item = $invoice->items()->create(['description' => 'Wire spool', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]);
        $invoice->recalculateTotals();

        $token = $this->tokenFor($this->manager);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
                'description' => 'Wire spool', 'quantity' => 2, 'unit_price' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('data.total', 200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_an_item_belonging_to_another_invoice_404s(): void
    {
        $client = $this->makeClient($this->manager);
        $invoiceA = $this->makeInvoice($this->manager, $client);
        $invoiceB = $this->makeInvoice($this->manager, $client);
        $item = $invoiceA->items()->create(['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'total' => 1]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->putJson("/api/v1/invoices/{$invoiceB->id}/items/{$item->id}", [
                'description' => 'X', 'quantity' => 1, 'unit_price' => 1,
            ])
            ->assertStatus(404);
    }

    // --- Send / mark paid ------------------------------------------------------

    public function test_sending_an_invoice_with_no_items_fails(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/send")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_sending_an_invoice_with_items_flips_it_to_sent(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);
        $invoice->items()->create(['description' => 'X', 'quantity' => 1, 'unit_price' => 100, 'total' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');
    }

    public function test_marking_a_sent_invoice_paid_records_the_full_balance(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_SENT, 'sent_at' => now(), 'subtotal' => 100, 'total' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/mark-paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paidAmount', 100)
            ->assertJsonPath('data.outstanding', 0);

        $this->assertDatabaseHas('payment_transactions', [
            'invoice_id' => $invoice->id, 'status' => 'completed', 'amount' => 100,
        ]);
    }

    public function test_a_draft_invoice_cannot_be_marked_paid(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/mark-paid")
            ->assertStatus(403);
    }

    // --- Delete / restore --------------------------------------------------

    public function test_a_manager_can_delete_and_restore_a_draft_invoice(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client);
        $token = $this->tokenFor($this->manager);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk();

        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/invoices/{$invoice->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);
    }

    public function test_a_paid_invoice_cannot_be_deleted(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->deleteJson("/api/v1/invoices/{$invoice->id}")
            ->assertStatus(403);
    }

    // --- Payment (Stripe) ----------------------------------------------------

    public function test_checkout_fails_gracefully_when_stripe_is_not_connected(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_SENT, 'sent_at' => now(), 'total' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/pay")
            ->assertStatus(422);
    }

    public function test_checkout_fails_when_nothing_is_outstanding(): void
    {
        PaymentProcessor::create([
            'key' => PaymentProcessor::STRIPE, 'display_name' => 'Stripe',
            'status' => PaymentProcessor::STATUS_ACTIVE,
            'credentials' => ['secret_key' => 'sk_test_fake'],
        ]);
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, [
            'status' => Invoice::STATUS_SENT, 'sent_at' => now(), 'total' => 100, 'paid_amount' => 100,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->postJson("/api/v1/invoices/{$invoice->id}/pay")
            ->assertStatus(422);
    }

    public function test_confirm_without_a_session_id_fails_gracefully(): void
    {
        $client = $this->makeClient($this->manager);
        $invoice = $this->makeInvoice($this->manager, $client, ['status' => Invoice::STATUS_SENT, 'sent_at' => now(), 'total' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($this->manager))
            ->getJson("/api/v1/invoices/{$invoice->id}/pay/confirm")
            ->assertStatus(422);
    }
}
