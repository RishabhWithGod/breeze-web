<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The billing overview: the invoices with their project and whether it is still
 * open, and a report of exactly the list being looked at.
 */
class InvoiceOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->client = Client::create(['user_id' => $this->manager->id, 'name' => 'Harborview']);
    }

    private function invoice(string $number, string $status, ?Job $job = null, float $total = 100): Invoice
    {
        return Invoice::create([
            'user_id' => $this->manager->id,
            'invoice_number' => $number,
            'client_id' => $this->client->id,
            'client' => 'Harborview',
            'job_id' => $job?->id,
            'invoice_date' => '2026-08-10',
            'due_date' => '2026-09-10',
            'tax_pct' => 0,
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'status' => $status,
        ]);
    }

    private function job(string $name, string $status): Job
    {
        return Job::create([
            'user_id' => $this->manager->id, 'name' => $name, 'client' => 'Harborview', 'status' => $status,
        ]);
    }

    public function test_each_invoice_says_which_project_it_is_on_and_whether_it_is_open(): void
    {
        $this->invoice('INV-1001', 'draft', $this->job('Riverside', 'in-progress'));
        $this->invoice('INV-1002', 'draft', $this->job('Lakeside', 'completed'));
        $this->invoice('INV-1003', 'draft');

        $this->actingAs($this->manager)
            ->get('/invoices')
            ->assertInertia(function ($page) {
                $rows = collect($page->toArray()['props']['invoices']['data'])->keyBy('invoiceNumber');

                $this->assertSame('Riverside', $rows['INV-1001']['jobName']);
                $this->assertTrue($rows['INV-1001']['projectOpen']);
                $this->assertFalse($rows['INV-1002']['projectOpen']);
                $this->assertNull($rows['INV-1003']['projectOpen']);
            });
    }

    public function test_the_report_is_a_csv_of_the_invoices_under_the_current_filters(): void
    {
        $this->invoice('INV-1001', 'sent', $this->job('Riverside', 'in-progress'), 250.5);
        $this->invoice('INV-1002', 'draft', null, 75);

        $all = $this->actingAs($this->manager)->get('/invoices/export');

        $all->assertOk();
        $this->assertStringContainsString('text/csv', $all->headers->get('Content-Type'));
        $csv = $all->streamedContent();
        $this->assertStringContainsString('"Invoice #",Client,Project', $csv);
        $this->assertStringContainsString('INV-1001,Harborview,Riverside,08/10/2026,09/10/2026,250.50', $csv);
        $this->assertStringContainsString('INV-1002', $csv);

        // Narrowed, it carries only what the list would show.
        $draft = $this->actingAs($this->manager)->get('/invoices/export?status=draft')->streamedContent();
        $this->assertStringContainsString('INV-1002', $draft);
        $this->assertStringNotContainsString('INV-1001', $draft);
    }

    public function test_the_report_holds_only_the_managers_own_invoices(): void
    {
        $this->invoice('INV-1001', 'sent');

        $other = User::factory()->create(['role' => 'Project Manager']);

        $csv = $this->actingAs($other)->get('/invoices/export')->streamedContent();

        $this->assertStringNotContainsString('INV-1001', $csv);
    }
}
