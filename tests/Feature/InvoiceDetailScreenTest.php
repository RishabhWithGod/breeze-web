<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The saved invoice: who it bills and where, which project and estimate it is
 * for, how each line got there — and what may still change once it exists.
 */
class InvoiceDetailScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Client $client;

    private Project $project;

    private Job $job;

    private Estimate $estimate;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->client = Client::create(['user_id' => $this->manager->id, 'name' => 'Harborview']);
        ClientAddress::create([
            'client_id' => $this->client->id, 'label' => 'HQ', 'address' => '1234 Business Ave, Denver, CO 80202', 'is_primary' => true,
        ]);
        $this->project = Project::create([
            'user_id' => $this->manager->id, 'client_id' => $this->client->id,
            'name' => 'Riverside Office', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $this->job = Job::create([
            'project_id' => $this->project->id, 'user_id' => $this->manager->id, 'name' => 'Fit-out',
            'client' => 'Harborview', 'client_id' => $this->client->id, 'status' => 'completed',
        ]);
        $this->estimate = Estimate::create([
            'project_id' => $this->project->id, 'job_id' => $this->job->id, 'client_id' => $this->client->id,
            'number' => 'EST-1001', 'client' => 'Harborview', 'project' => 'Riverside Office',
            'issued_on' => now()->toDateString(), 'status' => 'approved', 'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0, 'grand_total' => 5000,
        ]);
        $this->invoice = Invoice::create([
            'user_id' => $this->manager->id, 'invoice_number' => 'INV-1001', 'job_id' => $this->job->id,
            'estimate_id' => $this->estimate->id, 'client_id' => $this->client->id, 'client' => 'Harborview',
            'invoice_date' => '2026-08-27', 'due_date' => '2026-09-27', 'tax_pct' => 0,
            'subtotal' => 0, 'tax_total' => 0, 'total' => 0, 'status' => Invoice::STATUS_DRAFT,
        ]);
    }

    public function test_the_invoice_says_where_it_bills_and_which_project_and_estimate_it_is_for(): void
    {
        $this->actingAs($this->manager)
            ->get(route('invoices.show', $this->invoice))
            ->assertInertia(fn ($page) => $page
                ->where('invoice.projectName', 'Riverside Office')
                ->where('invoice.jobName', 'Fit-out')
                ->where('invoice.billingAddress', fn ($address) => str_contains($address, '1234 Business Ave'))
                ->where('invoice.estimateNumber', 'EST-1001')
                ->where('invoice.estimateTotal', 5000));
    }

    public function test_each_line_keeps_its_type_and_where_it_came_from(): void
    {
        $invoice = $this->invoice;
        $invoice->items()->create([
            'description' => 'Panel', 'source_category' => 'material', 'source' => InvoiceItem::SOURCE_ESTIMATE,
            'quantity' => 1, 'unit_price' => 200, 'total' => 200, 'position' => 0,
        ]);

        // A line typed on the saved invoice is marked as typed, and keeps the type chosen.
        $this->actingAs($this->manager)
            ->post(route('invoices.items.store', $invoice), [
                'description' => 'Permit', 'source_category' => 'other', 'quantity' => 1, 'unit_price' => 50,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->get(route('invoices.show', $invoice))
            ->assertInertia(fn ($page) => $page
                ->where('items.0.source', 'estimate')
                ->where('items.0.sourceCategory', 'material')
                ->where('items.1.source', 'manual')
                ->where('items.1.sourceCategory', 'other'));
    }

    public function test_a_lines_type_can_be_changed_and_the_totals_follow(): void
    {
        $item = $this->invoice->items()->create([
            'description' => 'Panel', 'source_category' => 'material', 'source' => InvoiceItem::SOURCE_ESTIMATE,
            'quantity' => 1, 'unit_price' => 200, 'total' => 200, 'position' => 0,
        ]);

        $this->actingAs($this->manager)
            ->put(route('invoices.items.update', [$this->invoice, $item]), [
                'description' => 'Panel', 'source_category' => 'equipment', 'quantity' => 2, 'unit_price' => 200,
            ])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('equipment', $item->source_category);
        // Changing the type does not make a copied line a typed one.
        $this->assertSame(InvoiceItem::SOURCE_ESTIMATE, $item->source);
        $this->assertSame('400.00', $this->invoice->fresh()->total);
    }

    public function test_editing_an_invoice_does_not_move_its_client_project_or_estimate(): void
    {
        $other = Client::create(['user_id' => $this->manager->id, 'name' => 'Someone Else']);

        $this->actingAs($this->manager)
            ->put(route('invoices.update', $this->invoice), [
                'client_id' => $other->id,
                'job_id' => null,
                'estimate_id' => null,
                'invoice_date' => '2026-09-01',
                'due_date' => '2026-10-01',
                'tax_pct' => 8.25,
                'notes' => 'Net 30',
            ])
            ->assertSessionHasNoErrors();

        $invoice = $this->invoice->fresh();

        // What may change: dates, tax, notes.
        $this->assertSame('2026-09-01', $invoice->invoice_date->toDateString());
        $this->assertSame('8.25', $invoice->tax_pct);
        $this->assertSame('Net 30', $invoice->notes);

        // What may not: who it is for, and what it bills.
        $this->assertSame($this->client->id, $invoice->client_id);
        $this->assertSame('Harborview', $invoice->client);
        $this->assertSame($this->job->id, $invoice->job_id);
        $this->assertSame($this->estimate->id, $invoice->estimate_id);
    }

    public function test_an_old_invoice_with_no_client_record_still_needs_one_picked(): void
    {
        $this->invoice->update(['client_id' => null]);

        $payload = ['invoice_date' => '2026-09-01', 'tax_pct' => 0];

        $this->actingAs($this->manager)
            ->put(route('invoices.update', $this->invoice), $payload)
            ->assertSessionHasErrors('client_id');

        $this->actingAs($this->manager)
            ->put(route('invoices.update', $this->invoice), [...$payload, 'client_id' => $this->client->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->client->id, $this->invoice->fresh()->client_id);
    }
}
