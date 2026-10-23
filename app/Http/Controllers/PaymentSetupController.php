<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentSetupRequest;
use App\Models\CompanyProfile;
use App\Models\Subscription;
use App\Services\Billing\StripeSubscriptions;
use App\Services\Billing\SubscriptionSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The third setup step: pick a plan and pay for it on Stripe.
 *
 * "Activate" sends the person to Stripe Checkout, where the card is entered and
 * the first payment taken; coming back, the session is looked up from Stripe
 * itself and only then is the subscription recorded. No card number ever touches
 * this app.
 */
class PaymentSetupController extends Controller
{
    public function create(Request $request, SubscriptionSummary $summary, StripeSubscriptions $stripe): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->needs_payment_setup) {
            return redirect()->route('home');
        }

        // Company and terms come first.
        if ($user->needs_company_setup) {
            return redirect()->route('company.setup.create');
        }
        if ($user->needs_terms_acceptance) {
            return redirect()->route('terms.create');
        }

        $own = Subscription::query()->where('user_id', $user->id)->first();

        return Inertia::render('PaymentSetup', [
            'plans' => $summary->build($user)['plans'],
            'annualDiscountPercent' => (int) config('subscription.annual_discount_percent'),
            'saved' => $own ? ['plan' => $own->plan, 'billingCycle' => $own->billing_cycle, 'seats' => $own->seats] : null,
            // False when no Stripe key is set: the screen says so instead of failing on click.
            'stripeReady' => $stripe->configured(),
            'cancelled' => $request->boolean('cancelled'),
        ]);
    }

    public function store(StorePaymentSetupRequest $request, StripeSubscriptions $stripe): SymfonyResponse
    {
        $user = $request->user();

        abort_if($user->needs_company_setup || $user->needs_terms_acceptance, 403);

        if (! $stripe->configured()) {
            return back()->withErrors(['plan' => 'Payments are not set up yet. Please contact support.']);
        }

        try {
            $checkout = $stripe->createCheckout(
                $user,
                $request->validated('plan'),
                $request->validated('billing_cycle'),
                (int) $request->validated('seats'),
                route('payment.setup.complete').'?session_id={CHECKOUT_SESSION_ID}',
                route('payment.setup.create').'?cancelled=1',
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe checkout could not be started', ['user' => $user->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['plan' => 'We could not reach Stripe. Please try again in a moment.']);
        }

        // Off to Stripe's own page — a full browser navigation, not an Inertia visit.
        return Inertia::location($checkout['url']);
    }

    /**
     * Where Stripe sends the person back to. The session is fetched from Stripe and
     * checked — belonging to this account and actually paid — before anything is saved.
     */
    public function complete(Request $request, StripeSubscriptions $stripe): RedirectResponse
    {
        $user = $request->user();
        $sessionId = (string) $request->query('session_id');

        if (! $user->needs_payment_setup) {
            return redirect()->route('home');
        }

        // Not a session address at all — nothing to ask Stripe about.
        abort_if($sessionId === '' || ! str_starts_with($sessionId, 'cs_'), 404);

        try {
            $session = $stripe->retrieveSession($sessionId);
        } catch (\Throwable $e) {
            Log::warning('Stripe checkout could not be confirmed', ['user' => $user->id, 'error' => $e->getMessage()]);

            return redirect()->route('payment.setup.create')
                ->with('warning', 'We could not confirm your payment with Stripe. If you were charged, contact support.');
        }

        $plan = $session['metadata']['plan'] ?? null;
        $cycle = $session['metadata']['cycle'] ?? null;
        $seats = max(1, (int) ($session['metadata']['seats'] ?? 1));

        $genuine = ($session['client_reference_id'] ?? null) === (string) $user->id
            && ($session['status'] ?? null) === 'complete'
            && ($session['payment_status'] ?? null) === 'paid'
            && config("subscription.plans.{$plan}") !== null
            && in_array($cycle, ['monthly', 'yearly'], true);

        abort_unless($genuine, 403);

        app(\App\Services\Billing\CheckoutRecorder::class)->record($user, $session, $plan, $cycle, $seats);

        return redirect()->route('payment.setup.confirmed');
    }

    /**
     * The Subscription Confirmed screen: what was bought, and the reference to quote.
     * Open for a while after paying — reloading it is fine, but it is not a page to
     * come back to next week.
     */
    public function confirmed(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $subscription = Subscription::query()->where('user_id', $user->id)->first();

        if (! $subscription || $subscription->confirmation_number === null || $subscription->updated_at->lt(now()->subHour())) {
            return redirect()->route('home');
        }

        $plan = config("subscription.plans.{$subscription->plan}");
        $owner = CompanyProfile::query()->whereKey($user->company_id)->first()?->user ?? $user;

        return Inertia::render('SubscriptionConfirmed', [
            'plan' => ['name' => $plan['name'], 'tagline' => $plan['tagline'], 'status' => $subscription->status],
            'seats' => $subscription->seats,
            'confirmationNumber' => $subscription->confirmation_number,
            'billingCycle' => $subscription->billing_cycle,
            'nextBillingDate' => $subscription->renews_on->toDateString(),
            'administrator' => ['name' => $owner->name, 'email' => $owner->email],
            'receiptUrl' => $subscription->receipt_url,
        ]);
    }
}
