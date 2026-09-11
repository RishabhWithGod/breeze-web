<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceSummaryCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Invoice Summary card's four figures.
 *
 * Every figure here is checked against invoices created with explicit,
 * known amounts and dates — there is no payments ledger in this schema, so
 * "paid this month" and "average days to pay" are only ever real facts about
 * `paid_at`/`invoice_date` this app itself recorded, never a guessed number.
 */
class InvoiceSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->clientId = $this->manager->clients()->create(['name' => 'Test Client'])->id;
    }

    public function test_total_outstanding_only_counts_non_draft_invoices(): void
    {
        $this->makeInvoice(['status' => 'draft', 'total' => 1000, 'paid_amount' => 0]);
        $this->makeInvoice(['status' => 'sent', 'total' => 500, 'paid_amount' => 0]);
        $this->makeInvoice(['status' => 'sent', 'total' => 300, 'paid_amount' => 100]);

        $summary = app(InvoiceSummaryCalculator::class)->calculate($this->manager);

        // Draft is excluded; sent contributes its full outstanding balance.
        $this->assertSame(700.0, $summary['totalOutstanding']);
    }

    public function test_overdue_only_counts_sent_invoices_whose_due_date_has_passed(): void
    {
        $this->makeInvoice([
            'status' => 'sent', 'total' => 400, 'paid_amount' => 0,
            'due_date' => now()->subDays(2)->toDateString(),
        ]);
        $this->makeInvoice([
            'status' => 'sent', 'total' => 900, 'paid_amount' => 0,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $summary = app(InvoiceSummaryCalculator::class)->calculate($this->manager);

        $this->assertSame(400.0, $summary['overdue']);
    }

    public function test_paid_this_month_only_counts_invoices_actually_paid_this_month(): void
    {
        $this->makeInvoice(['status' => 'paid', 'total' => 250, 'paid_amount' => 250, 'paid_at' => now()]);
        $this->makeInvoice(['status' => 'paid', 'total' => 600, 'paid_amount' => 600, 'paid_at' => now()->subMonths(2)]);

        $summary = app(InvoiceSummaryCalculator::class)->calculate($this->manager);

        $this->assertSame(250.0, $summary['paidThisMonth']);
    }

    public function test_average_days_to_pay_is_computed_from_real_invoice_and_paid_dates(): void
    {
        $this->makeInvoice([
            'status' => 'paid', 'total' => 100, 'paid_amount' => 100,
            'invoice_date' => '2026-08-01', 'paid_at' => '2026-08-11 00:00:00',
        ]);
        $this->makeInvoice([
            'status' => 'paid', 'total' => 200, 'paid_amount' => 200,
            'invoice_date' => '2026-08-01', 'paid_at' => '2026-08-21 00:00:00',
        ]);

        $summary = app(InvoiceSummaryCalculator::class)->calculate($this->manager);

        // 10 days and 20 days paid — average 15, not a fabricated figure.
        $this->assertSame(15.0, $summary['averageDaysToPay']);
    }

    public function test_average_days_to_pay_is_null_when_nothing_has_been_paid_yet(): void
    {
        $this->makeInvoice(['status' => 'sent', 'total' => 100, 'paid_amount' => 0]);

        $summary = app(InvoiceSummaryCalculator::class)->calculate($this->manager);

        $this->assertNull($summary['averageDaysToPay']);
    }

    private function makeInvoice(array $attributes): Invoice
    {
        static $sequence = 0;
        $sequence++;

        return Invoice::create([
            'client_id' => $this->clientId,
            'invoice_number' => "INV-{$sequence}",
            'client' => 'Test Client',
            'invoice_date' => $attributes['invoice_date'] ?? now()->toDateString(),
            'due_date' => $attributes['due_date'] ?? null,
            'subtotal' => $attributes['total'],
            'tax_total' => 0,
            'total' => $attributes['total'],
            'paid_amount' => $attributes['paid_amount'] ?? 0,
            'status' => $attributes['status'],
            'paid_at' => $attributes['paid_at'] ?? null,
        ]);
    }
}
