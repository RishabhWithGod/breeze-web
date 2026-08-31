<?php

namespace Tests\Feature;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Invoices: numbering, the create → send → paid lifecycle, line-item totals,
 * and who is allowed to do what.
 *
 * `total` is never trusted from the client — every line-item write recomputes
 * it server-side, the same guarantee `TimeEntryTest` and `Estimate` already
 * hold their own totals to.
 */
class InvoiceTest extends TestCase
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

    public function test_creating_an_invoice_assigns_a_unique_sequential_number(): void
    {
        $first = $this->createInvoice();
        $second = $this->createInvoice(['client' => 'Second Client']);

        $this->assertSame('INV-1001', $first->invoice_number);
        $this->assertSame('INV-1002', $second->invoice_number);
    }

    public function test_a_new_invoice_starts_as_a_draft_with_zero_totals(): void
    {
        $invoice = $this->createInvoice();

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame('0.00', $invoice->total);
    }

    public function test_client_is_required_to_create_an_invoice(): void
    {
        $this->actingAs($this->manager)->post('/invoices', [
            'client' => '',
            'invoice_date' => '2026-08-10',
            'tax_pct' => 0,
        ])->assertSessionHasErrors('client');

        $this->assertSame(0, Invoice::count());
    }

    public function test_an_electrician_cannot_create_an_invoice(): void
    {
        $this->actingAs($this->electrician)->post('/invoices', [
            'client' => 'Apex Construction',
            'invoice_date' => '2026-08-10',
            'tax_pct' => 0,
        ])->assertForbidden();

        $this->assertSame(0, Invoice::count());
    }

    public function test_adding_a_line_item_recomputes_the_invoice_totals(): void
    {
        $invoice = $this->createInvoice(['tax_pct' => 10]);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/items", [
            'description' => 'Panel installation',
            'quantity' => 2,
            'unit_price' => 500,
        ])->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame('1000.00', $invoice->subtotal);
        $this->assertSame('100.00', $invoice->tax_total);
        $this->assertSame('1100.00', $invoice->total);
    }

    public function test_removing_a_line_item_recomputes_the_totals_back_down(): void
    {
        $invoice = $this->createInvoice();
        $this->addItem($invoice, 'Materials', 1, 200);
        $item = $this->addItem($invoice, 'Labor', 1, 300);

        $this->actingAs($this->manager)->delete("/invoices/{$invoice->id}/items/{$item->id}")
            ->assertSessionHasNoErrors();

        $this->assertSame('200.00', $invoice->refresh()->total);
    }

    public function test_an_invoice_cannot_be_sent_without_at_least_one_line_item(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/send")
            ->assertSessionHasErrors('items');

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->refresh()->status);
    }

    public function test_sending_then_marking_paid_moves_the_invoice_through_its_real_lifecycle(): void
    {
        $invoice = $this->createInvoice();
        $this->addItem($invoice, 'Service call', 1, 250);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/send")
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);
        $this->assertNotNull($invoice->sent_at);

        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/mark-paid")
            ->assertSessionHasNoErrors();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('250.00', $invoice->paid_amount);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(0.0, $invoice->outstanding());
    }

    public function test_a_sent_invoice_past_its_due_date_displays_as_overdue_without_changing_its_stored_status(): void
    {
        $invoice = $this->createInvoice(['due_date' => now()->subDays(3)->toDateString()]);
        $this->addItem($invoice, 'Rough-in', 1, 400);
        // The item POST recalculated `total` in the database — refresh before
        // reading it back, rather than trusting this stale in-memory copy.
        $invoice->refresh()->update(['status' => Invoice::STATUS_SENT, 'sent_at' => now()->subDays(5)]);

        $this->assertTrue($invoice->isOverdue());
        $this->assertSame('overdue', $invoice->displayStatus());
        // The real, stored workflow state never changes to "overdue" — it is derived.
        $this->assertSame(Invoice::STATUS_SENT, $invoice->fresh()->status);

        $this->actingAs($this->manager)
            ->get('/invoices?status=overdue')
            ->assertInertia(fn (Assert $page) => $page->has('invoices.data', 1));
    }

    public function test_a_paid_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->createInvoice();
        $this->addItem($invoice, 'Full job', 1, 1000);
        $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_amount' => 1000, 'paid_at' => now()]);

        $this->actingAs($this->manager)->delete("/invoices/{$invoice->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);
    }

    public function test_a_draft_invoice_can_be_deleted_by_a_manager(): void
    {
        $invoice = $this->createInvoice();

        $this->actingAs($this->manager)->delete("/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    public function test_generating_an_invoice_from_an_estimate_copies_its_line_items(): void
    {
        $job = $this->makeJob();
        $estimate = Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-1001',
            'client' => 'Apex Construction',
            'project' => 'Panel upgrade',
            'issued_on' => '2026-08-01',
            'amount' => 500,
            'status' => 'approved',
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id,
            'category' => 'labor',
            'description' => 'Install subpanel',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 500,
            'total' => 500,
            'source' => 'manual',
        ]);

        $this->actingAs($this->manager)->post('/invoices', [
            'client' => $estimate->client,
            'job_id' => $job->id,
            'estimate_id' => $estimate->id,
            'invoice_date' => '2026-08-10',
            'tax_pct' => 0,
        ])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame($estimate->id, $invoice->estimate_id);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Install subpanel', $invoice->items()->first()->description);
        $this->assertSame('500.00', $invoice->total);
    }

    private function createInvoice(array $overrides = []): Invoice
    {
        $this->actingAs($this->manager)->post('/invoices', [
            'client' => $overrides['client'] ?? 'Apex Construction',
            'invoice_date' => '2026-08-10',
            'due_date' => $overrides['due_date'] ?? null,
            'tax_pct' => $overrides['tax_pct'] ?? 0,
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->first();
    }

    private function addItem(Invoice $invoice, string $description, float $quantity, float $unitPrice): InvoiceItem
    {
        $this->actingAs($this->manager)->post("/invoices/{$invoice->id}/items", [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ])->assertSessionHasNoErrors();

        // Not `$invoice->items()->latest('id')` — that relation also orders by
        // `position`, which wins over a chained `latest('id')` and would hand
        // back the *first* line once a second one exists, not the new one.
        return InvoiceItem::where('invoice_id', $invoice->id)->orderByDesc('id')->first();
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Apex Construction',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }
}
