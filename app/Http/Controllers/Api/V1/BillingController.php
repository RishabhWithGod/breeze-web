<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\InvoiceSummaryCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The mobile Dashboard's billing KPIs — the exact same
 * `InvoiceSummaryCalculator` the web Dashboard's Billing Snapshot panel
 * uses, scoped the same way (`Invoice::ownedBy($user)`), so "Revenue MTD"/
 * "Overdue Invoices" on mobile always agree with web for the same
 * signed-in manager. There was no mobile billing endpoint at all before
 * this — the app rendered mock invoice data instead.
 */
class BillingController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly InvoiceSummaryCalculator $calculator) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $summary = $this->calculator->calculate($user);

        $overdueCount = Invoice::query()
            ->ownedBy($user)
            ->displayStatus('overdue')
            ->count();

        return $this->ok([
            'totalOutstanding' => $summary['totalOutstanding'],
            'overdue' => $summary['overdue'],
            'overdueCount' => $overdueCount,
            'paidThisMonth' => $summary['paidThisMonth'],
            'averageDaysToPay' => $summary['averageDaysToPay'],
        ]);
    }
}
