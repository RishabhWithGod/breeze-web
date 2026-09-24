<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Services\Payments\StripeConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paying an invoice online through Stripe Checkout — the exact same
 * `StripeConnector`/`PaymentProcessor`/`PaymentTransaction` calls as web's
 * `InvoicePaymentController`, reshaped from web's Inertia-redirect response
 * into JSON a mobile client can act on:
 *
 * - `checkout()` returns `{checkoutUrl, sessionId}` instead of
 *   `Inertia::location($session['url'])` — the app opens `checkoutUrl` in an
 *   in-app browser tab (no Stripe SDK, no key, ever, on this side).
 * - `confirm()` takes the same `session_id` web's success redirect carries,
 *   but the mobile app supplies it directly (it already has it from
 *   `checkout()`) rather than relying on intercepting a redirect — there is
 *   no mobile deep-link/URL-scheme wiring in this change, so success/cancel
 *   URLs stay pointed at the existing web pages, which the in-app browser
 *   lands on and the user simply closes; the app confirms status itself via
 *   this endpoint. No behaviour differs from web's own verification logic
 *   (still a direct Stripe API lookup, still deduped by `external_reference`
 *   the same way, subtleties included) — only the response shape changes.
 */
class InvoicePaymentController extends Controller
{
    use ApiResponses;

    public function checkout(Request $request, Invoice $invoice, StripeConnector $stripe): JsonResponse
    {
        $this->authorize('markPaid', $invoice);

        $processor = PaymentProcessor::query()->where('key', PaymentProcessor::STRIPE)->first();

        if (! $processor || ! $processor->isConnected()) {
            return $this->fail('Connect Stripe in Payment Settings before taking an online payment.', 422);
        }

        $secretKey = (string) ($processor->credentials['secret_key'] ?? '');
        $outstanding = $invoice->outstanding();

        if ($secretKey === '' || $outstanding <= 0) {
            return $this->fail('This invoice has nothing outstanding to collect.', 422);
        }

        $session = $stripe->createCheckoutSession(
            secretKey: $secretKey,
            invoiceNumber: $invoice->invoice_number,
            amount: $outstanding,
            successUrl: route('invoices.pay.confirm', $invoice).'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('invoices.show', $invoice),
            clientReferenceId: (string) $invoice->id,
        );

        if (! $session) {
            return $this->fail('Stripe could not start a checkout session. Try again in a moment.', 422);
        }

        return $this->ok(['checkoutUrl' => $session['url'], 'sessionId' => $session['id']]);
    }

    public function confirm(Request $request, Invoice $invoice, StripeConnector $stripe): JsonResponse
    {
        $this->authorize('markPaid', $invoice);

        $sessionId = (string) $request->query('session_id', '');
        $processor = PaymentProcessor::query()->where('key', PaymentProcessor::STRIPE)->first();
        $secretKey = (string) ($processor?->credentials['secret_key'] ?? '');

        if ($sessionId === '' || $secretKey === '') {
            return $this->fail('Nothing to confirm — no checkout session was found.', 422);
        }

        if (PaymentTransaction::query()->where('external_reference', $sessionId)->exists()) {
            return $this->ok(['status' => 'already_recorded'], 'Payment already recorded.');
        }

        $session = $stripe->retrieveCheckoutSession($secretKey, $sessionId);

        if (! $session) {
            return $this->fail('Could not confirm this payment with Stripe. If you were charged, it will still be recorded — contact support if it is not, shortly.', 422);
        }

        if (! $session['paid']) {
            return $this->fail('The payment was not completed.', 422);
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

        return $this->ok(['status' => 'paid'], "{$invoice->invoice_number} was paid via Stripe.");
    }
}
