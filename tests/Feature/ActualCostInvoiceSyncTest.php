<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job-linked draft invoice's Subtotal/Tax/Total must never drift from the
 * job's own Job Costing "Actual" figures — editing a Journeyman's hours or
 * logging a material/equipment cost immediately updates the invoice too, not
 * just the Job Costing screen. A sent/paid invoice is history and is left
 * alone regardless of what happens to the job afterwards.
 */
class ActualCostInvoiceSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Job $job;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);

        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Harborview', 'labor_rate' => 40]);
        $project = Project::create([
            'user_id' => $this->manager->id,
            'client_id' => $client->id,
            'name' => 'Harborview Panel Upgrade',
            'client' => $client->name,
            'status' => 'in-progress',
        ]);

        $this->job = Job::create([
            'user_id' => $this->manager->id,
            'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Harborview Panel Upgrade',
            'client' => $client->name,
            // Not `completed`: `storeCostEntry`/`destroyCostEntry` still
            // refuse a locked job (unchanged by this feature) — only the
            // Journeyman Hours edit was freed from that in the prior change.
            'status' => 'in-progress',
        ]);

        $this->invoice = Invoice::create([
            'user_id' => $this->manager->id,
            'invoice_number' => Invoice::nextNumber($this->manager),
            'job_id' => $this->job->id,
            'client' => $client->name,
            'invoice_date' => now()->toDateString(),
            'tax_pct' => 10,
            'subtotal' => 0,
            'tax_total' => 0,
            'total' => 0,
            'status' => Invoice::STATUS_DRAFT,
        ]);
    }

    public function test_editing_labor_hours_updates_the_invoices_subtotal_tax_and_total_together(): void
    {
        $electrician = User::factory()->create(['role' => 'Electrician']);
        TimeEntry::create([
            'job_id' => $this->job->id,
            'user_id' => $electrician->id,
            'date' => now()->toDateString(),
            'hours' => 5,
            'regular_hours' => 5,
            'overtime_hours' => 0,
            'billable' => true,
            'source' => 'manual',
            'status' => TimeEntry::STATUS_SUBMITTED,
        ])->recordInitialStatus();

        $this->actingAs($this->manager)
            ->put("/jobs/{$this->job->id}/journeyman-hours/{$electrician->id}", ['hours' => 5])
            ->assertSessionHasNoErrors();

        // 5 hours * $40/hr = $200 subtotal, 10% tax = $20, total $220 —
        // subtotal, tax and total all moved together, from one saved figure.
        $this->invoice->refresh();
        $this->assertSame('200.00', $this->invoice->subtotal);
        $this->assertSame('20.00', $this->invoice->tax_total);
        $this->assertSame('220.00', $this->invoice->total);

        $laborLine = $this->invoice->items()->where('source_category', InvoiceItem::CATEGORY_LABOR)->sole();
        $this->assertSame('200.00', $laborLine->total);

        // Editing again moves all three again, together.
        $this->actingAs($this->manager)
            ->put("/jobs/{$this->job->id}/journeyman-hours/{$electrician->id}", ['hours' => 10])
            ->assertSessionHasNoErrors();

        $this->invoice->refresh();
        $this->assertSame('400.00', $this->invoice->subtotal);
        $this->assertSame('40.00', $this->invoice->tax_total);
        $this->assertSame('440.00', $this->invoice->total);
    }

    public function test_logging_a_material_cost_updates_the_invoice_alongside_labor(): void
    {
        $electrician = User::factory()->create(['role' => 'Electrician']);
        TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $electrician->id, 'date' => now()->toDateString(),
            'hours' => 5, 'regular_hours' => 5, 'overtime_hours' => 0, 'billable' => true,
            'source' => 'manual', 'status' => TimeEntry::STATUS_APPROVED,
        ])->recordInitialStatus();

        $this->actingAs($this->manager)->post("/jobs/{$this->job->id}/costing/entries", [
            'category' => JobCostEntry::CATEGORY_MATERIAL,
            'description' => 'Panel and breakers',
            'amount' => 300,
            'incurred_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        // Labor 5*40=200 + Material 300 = 500 subtotal, 10% tax = 50, total 550.
        $this->invoice->refresh();
        $this->assertSame('500.00', $this->invoice->subtotal);
        $this->assertSame('50.00', $this->invoice->tax_total);
        $this->assertSame('550.00', $this->invoice->total);

        $entry = JobCostEntry::sole();
        $this->actingAs($this->manager)
            ->delete("/jobs/{$this->job->id}/costing/entries/{$entry->id}")
            ->assertSessionHasNoErrors();

        // Material line removed entirely, not left at zero — back to labor only.
        $this->invoice->refresh();
        $this->assertSame('200.00', $this->invoice->subtotal);
        $this->assertSame('20.00', $this->invoice->tax_total);
        $this->assertSame('220.00', $this->invoice->total);
        $this->assertSame(1, $this->invoice->items()->count());
    }

    public function test_a_sent_invoice_is_never_touched_by_a_later_actual_cost_change(): void
    {
        $this->invoice->update(['status' => Invoice::STATUS_SENT, 'sent_at' => now(), 'total' => 999, 'subtotal' => 909, 'tax_total' => 90]);

        $electrician = User::factory()->create(['role' => 'Electrician']);
        TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $electrician->id, 'date' => now()->toDateString(),
            'hours' => 5, 'regular_hours' => 5, 'overtime_hours' => 0, 'billable' => true,
            'source' => 'manual', 'status' => TimeEntry::STATUS_APPROVED,
        ])->recordInitialStatus();

        $this->actingAs($this->manager)
            ->put("/jobs/{$this->job->id}/journeyman-hours/{$electrician->id}", ['hours' => 5])
            ->assertSessionHasNoErrors();

        $this->invoice->refresh();
        $this->assertSame('999.00', $this->invoice->total);
        $this->assertSame(0, $this->invoice->items()->count());
    }
}
