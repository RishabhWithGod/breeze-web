<?php

namespace App\Http\Controllers;

use App\Models\FeedItem;
use App\Models\Invoice;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Notifications\InvoiceStatusChanged;
use App\Services\Activity\FeedItemRecorder;
use App\Services\Payments\StripeConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Paying an invoice online, through Stripe Checkout — separate from
 * `InvoiceDetailController::markPaid()`, which is the honestly-manual path
 * (a check, a wire, cash). This controller's `confirm()` is the only place
 * a `PaymentTransaction` is ever written with `payment_processor_id` set,
 * because it's the only place that actually asked the processor whether the
 * money moved rather than taking a click's word for it.
 */
class InvoicePaymentController extends Controller
{
    public function __construct(private readonly FeedItemRecorder $activity) {}

    /**
     * Creates a real Checkout Session and sends the browser to Stripe's own
     * hosted page. `Inertia::location()` (a 409 the client turns into a full
     * `window.location` navigation) is used deliberately — a plain Inertia
     * redirect would try to fetch Stripe's URL as if it were another page in
     * this app.
     */
    public function checkout(Request $request, Invoice $invoice): SymfonyResponse
    {
        $this->authorize('markPaid', $invoice);

        $processor = PaymentProcessor::query()->where('key', PaymentProcessor::STRIPE)->first();

        if (! $processor || ! $processor->isConnected()) {
            return back()->with('warning', 'Connect Stripe in Payment Settings before taking an online payment.');
        }

        $secretKey = (string) ($processor->credentials['secret_key'] ?? '');
        $outstanding = $invoice->outstanding();

        if ($secretKey === '' || $outstanding <= 0) {
            return back()->with('warning', 'This invoice has nothing outstanding to collect.');
        }

        $session = app(StripeConnector::class)->createCheckoutSession(
            secretKey: $secretKey,
            invoiceNumber: $invoice->invoice_number,
            amount: $outstanding,
            successUrl: route('invoices.pay.confirm', $invoice).'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('invoices.show', $invoice),
            clientReferenceId: (string) $invoice->id,
        );

        if (! $session) {
            return back()->with('warning', 'Stripe could not start a checkout session. Try again in a moment.');
        }

        return Inertia::location($session['url']);
    }

    /**
     * Where Stripe sends the browser back after checkout. The URL alone
     * proves nothing — anyone could type it — so this asks Stripe directly
     * whether the session actually paid before recording anything.
     */
    public function confirm(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('markPaid', $invoice);

        $sessionId = (string) $request->query('session_id', '');
        $processor = PaymentProcessor::query()->where('key', PaymentProcessor::STRIPE)->first();
        $secretKey = (string) ($processor?->credentials['secret_key'] ?? '');

        if ($sessionId === '' || $secretKey === '') {
            return redirect()->route('invoices.show', $invoice)
                ->with('warning', 'Nothing to confirm — no checkout session was found.');
        }

        // Already recorded — Stripe can redirect the browser here more than
        // once (a refresh, a double-back), and this must stay a no-op past
        // the first time rather than double-billing the invoice.
        if (PaymentTransaction::query()->where('external_reference', $sessionId)->exists()) {
            return redirect()->route('invoices.show', $invoice)->with('success', 'Payment already recorded.');
        }

        $session = app(StripeConnector::class)->retrieveCheckoutSession($secretKey, $sessionId);

        if (! $session) {
            Log::warning('Could not retrieve Stripe checkout session on return', ['invoice_id' => $invoice->id, 'session_id' => $sessionId]);

            return redirect()->route('invoices.show', $invoice)
                ->with('warning', 'Could not confirm this payment with Stripe. If you were charged, it will still be recorded — contact support if it is not, shortly.');
        }

        if (! $session['paid']) {
            return redirect()->route('invoices.show', $invoice)->with('warning', 'The payment was not completed.');
        }

        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => $invoice->paid_amount + $session['amountTotal'],
            'paid_at' => now(),
        ]);

        PaymentTransaction::create([
            'invoice_id' => $invoice->id,
            'payment_processor_id' => $processor->id,
            'amount' => $session['amountTotal'],
            'status' => PaymentTransaction::STATUS_COMPLETED,
            'client' => $invoice->client,
            'description' => "Invoice {$invoice->invoice_number} paid online via Stripe",
            'external_reference' => $session['paymentIntentId'] ?? $sessionId,
            'occurred_at' => now(),
            'recorded_by' => null,
        ]);

        $creator = $invoice->creator;
        if ($creator) {
            $creator->notify(new InvoiceStatusChanged($invoice, InvoiceStatusChanged::PAID));
        }

        $this->activity->record(
            FeedItem::DASHBOARD_ACTIVITY,
            "Invoice {$invoice->invoice_number} paid online via Stripe",
            'file-text',
            'butter',
        );

        return redirect()->route('invoices.show', $invoice)->with('success', "{$invoice->invoice_number} was paid via Stripe.");
    }
}
