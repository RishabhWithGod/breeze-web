<?php

namespace Tests\Feature;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Billing\EstimateInvoiceSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `EstimateInvoiceSync` copies an estimate's current items onto its draft
 * invoice once, at creation (`InvoiceController::store()`) — every line,
 * with its real category, not a collapsed summary.
 *
 * Deliberately a one-time copy, not an ongoing mirror: an earlier version of
 * this re-synced on every visit to the invoice, which fought with the whole
 * point of the "Line Items" editor — a hand-made edit would just be
 * overwritten the next time the page loaded. Line items stay freely editable
 * right up to send, and whatever total that editing settles on is the one
 * that gets sent — these tests guard that an edit survives a reload, and
 * that a later change to the *source* estimate does not reach back into an
 * invoice already raised from it.
 */
class EstimateInvoiceSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->project = Project::create([
            'user_id' => $this->manager->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);
    }

    /** The full estimate copies over, with each line's real category. */
    public function test_syncing_copies_every_estimate_line_with_its_category(): void
    {
        $estimate = $this->estimateWith([
            ['Panel install', 'labor', 500],
            ['Conduit run', 'material', 200],
            ['EM2 fixture', 'fixture', 150],
            ['Scissor lift', 'equipment', 100],
        ]);
        $invoice = $this->draftInvoiceFor($estimate);

        app(EstimateInvoiceSync::class)->sync($invoice);

        $this->assertSame(4, $invoice->items()->count());
        $byDescription = InvoiceItem::where('invoice_id', $invoice->id)->get()->keyBy('description');
        $this->assertSame('labor', $byDescription['Panel install']->source_category);
        $this->assertSame('material', $byDescription['Conduit run']->source_category);
        // Fixture folds into material, the same as everywhere else this app totals a category.
        $this->assertSame('material', $byDescription['EM2 fixture']->source_category);
        $this->assertSame('equipment', $byDescription['Scissor lift']->source_category);
        $this->assertSame('950.00', $invoice->fresh()->total);
    }

    /** A hand-made edit to a line survives reloading the invoice — it is never silently reverted. */
    public function test_editing_a_line_is_preserved_across_a_reload(): void
    {
        $estimate = $this->estimateWith([['Panel install', 'labor', 500]]);
        $invoice = $this->draftInvoiceFor($estimate);
        app(EstimateInvoiceSync::class)->sync($invoice);
        $item = $invoice->items()->sole();

        $this->actingAs($this->manager)->put("/invoices/{$invoice->id}/items/{$item->id}", [
            'description' => $item->description,
            'quantity' => 2,
            'unit_price' => 500,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 1)
                ->where('items.0.quantity', 2));
        $this->assertSame('1000.00', $invoice->fresh()->total);
    }

    /** A line added to the estimate after the invoice was raised from it never reaches that invoice. */
    public function test_a_later_change_to_the_estimate_does_not_reach_an_already_raised_invoice(): void
    {
        $estimate = $this->estimateWith([['Panel install', 'labor', 500]]);
        $invoice = $this->draftInvoiceFor($estimate);
        app(EstimateInvoiceSync::class)->sync($invoice);

        $this->addItem($estimate, 'New conduit run', 'material', 200);

        $this->actingAs($this->manager)
            ->get("/invoices/{$invoice->id}")
            ->assertInertia(fn (Assert $page) => $page->has('items', 1));
    }

    /** An invoice with no estimate behind it is never touched by this sync. */
    public function test_an_invoice_with_no_estimate_is_left_alone(): void
    {
        $invoice = Invoice::create([
            'user_id' => $this->manager->id,
            'project_id' => $this->project->id,
            'invoice_number' => 'INV-500001',
            'client' => $this->project->name,
            'invoice_date' => '2026-08-10',
            'status' => Invoice::STATUS_DRAFT,
        ]);
        $invoice->items()->create([
            'description' => 'Hand-typed line',
            'quantity' => 1,
            'unit_price' => 300,
            'total' => 300,
            'position' => 0,
        ]);

        app(EstimateInvoiceSync::class)->sync($invoice);

        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Hand-typed line', $invoice->items()->first()->description);
    }

    /** Once sent, the invoice is never touched by this sync either — same rule, one more way to reach it. */
    public function test_a_sent_invoice_is_never_synced(): void
    {
        $estimate = $this->estimateWith([['Panel install', 'labor', 500]]);
        $invoice = $this->draftInvoiceFor($estimate);
        $invoice->update(['status' => Invoice::STATUS_SENT, 'sent_at' => now()]);

        app(EstimateInvoiceSync::class)->sync($invoice);

        $this->assertSame(0, $invoice->items()->count());
    }

    /** @param  list<array{0: string, 1: string, 2: float}>  $lines */
    private function estimateWith(array $lines): Estimate
    {
        $estimate = Estimate::create([
            'project_id' => $this->project->id,
            'number' => Estimate::nextNumber($this->manager),
            'client' => $this->project->name,
            'project' => $this->project->name,
            'issued_on' => now()->toDateString(),
            'status' => 'approved',
            'kind' => Estimate::KIND_STANDALONE,
            'amount' => 0,
        ]);

        foreach ($lines as [$description, $category, $amount]) {
            $this->addItem($estimate, $description, $category, $amount);
        }

        return $estimate;
    }

    private function addItem(Estimate $estimate, string $description, string $category, float $amount): void
    {
        $estimate->items()->create([
            'category' => $category,
            'description' => $description,
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => $amount,
            'total' => $amount,
            'source' => 'manual',
        ]);

        $estimate->recalculateTotals();
    }

    private function draftInvoiceFor(Estimate $estimate): Invoice
    {
        return Invoice::create([
            'user_id' => $this->manager->id,
            'project_id' => $this->project->id,
            'estimate_id' => $estimate->id,
            'invoice_number' => 'INV-'.random_int(100000, 999999),
            'client' => $this->project->name,
            'invoice_date' => now()->toDateString(),
            'status' => Invoice::STATUS_DRAFT,
        ]);
    }
}
