<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * The Invoice Summary card's four figures — a live aggregate over `invoices`,
 * never a stored duplicate.
 *
 * "Average Days to Pay" is computed only from invoices this app itself marked
 * paid — `paid_at` minus `invoice_date` — since there is no payments ledger to
 * read a real collection date from. An invoice with no `paid_at` contributes
 * nothing rather than a guessed date.
 */
class InvoiceSummaryCalculator
{
    /** @return array{totalOutstanding: float, overdue: float, paidThisMonth: float, averageDaysToPay: ?float} */
    public function calculate(): array
    {
        $unpaid = Invoice::query()->where('status', '!=', Invoice::STATUS_DRAFT);

        $totalOutstanding = round(
            (float) $unpaid->clone()->sum('total') - (float) $unpaid->clone()->sum('paid_amount'),
            2,
        );

        $overdue = round(
            (float) Invoice::query()
                ->where('status', Invoice::STATUS_SENT)
                ->whereNotNull('due_date')
                ->where('due_date', '<', Carbon::today())
                ->whereColumn('paid_amount', '<', 'total')
                ->get()
                ->sum(fn (Invoice $invoice) => $invoice->outstanding()),
            2,
        );

        $paidThisMonth = round(
            (float) Invoice::query()
                ->where('status', Invoice::STATUS_PAID)
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('paid_amount'),
            2,
        );

        $paidInvoices = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->get(['invoice_date', 'paid_at']);

        $averageDaysToPay = $paidInvoices->isEmpty()
            ? null
            : round(
                $paidInvoices->avg(fn (Invoice $invoice) => $invoice->invoice_date
                    ->startOfDay()
                    ->diffInDays($invoice->paid_at->copy()->startOfDay())),
                1,
            );

        return [
            'totalOutstanding' => $totalOutstanding,
            'overdue' => $overdue,
            'paidThisMonth' => $paidThisMonth,
            'averageDaysToPay' => $averageDaysToPay,
        ];
    }
}
