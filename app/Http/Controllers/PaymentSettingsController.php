<?php

namespace App\Http\Controllers;

use App\Models\BillingSetting;
use App\Models\CompanyProfile;
use App\Models\PaymentMethod;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionCard;
use App\Models\User;
use App\Notifications\PaymentProcessorStatusChanged;
use App\Policies\PaymentSettingsPolicy;
use App\Services\Billing\PlanPricing;
use App\Services\Billing\SubscriptionSummary;
use App\Services\Payments\PaymentMethodService;
use App\Services\Payments\PaymentProcessorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment Settings: connected processors, saved payment methods, company
 * billing preferences, and the real transaction history behind them all —
 * one screen, reading and writing the same tables the rest of Billing does.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(
        private readonly PaymentProcessorService $processors,
        private readonly PaymentMethodService $methods,
    ) {}

    public function index(Request $request): Response
    {
        $policy = app(PaymentSettingsPolicy::class);
        abort_unless($policy->manage($request->user()), 403);

        // The three processors this app knows how to speak to always exist as
        // a row — `not_connected` until someone actually connects real
        // credentials, never assumed `active`.
        foreach (PaymentProcessor::KEYS as $key) {
            PaymentProcessor::firstOrCreate(['key' => $key], [
                'display_name' => PaymentProcessor::DISPLAY_NAMES[$key],
                'status' => PaymentProcessor::STATUS_NOT_CONNECTED,
            ]);
        }

        $processors = PaymentProcessor::orderBy('id')->get()->map(fn (PaymentProcessor $processor) => [
            'id' => $processor->id,
            'key' => $processor->key,
            'displayName' => $processor->display_name,
            'status' => $processor->status,
            'isConnected' => $processor->isConnected(),
            'connectedAt' => $processor->connected_at?->toISOString(),
            'lastTestedAt' => $processor->last_tested_at?->toISOString(),
            'lastError' => $processor->last_error,
            'credentialFields' => $this->processors->connectorFor($processor->key)->requiredCredentialFields(),
        ]);

        $methods = PaymentMethod::with('processor')->orderByDesc('is_default')->orderByDesc('id')->get()
            ->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'brand' => $method->brand,
                'lastFour' => $method->last_four,
                'expiry' => $method->expiry(),
                'isDefault' => $method->is_default,
                'isExpired' => $method->isExpired(),
                'processorName' => $method->processor->display_name,
            ]);

        $transactions = PaymentTransaction::with(['invoice', 'processor'])
            ->latest('occurred_at')
            ->paginate(config('payments.per_page'))
            ->withQueryString();

        $billingSettings = BillingSetting::current();

        return Inertia::render('PaymentSettings', [
            // The company's plan, and how much of it is used.
            'subscription' => app(SubscriptionSummary::class)->build($request->user()),
            // What this account pays Breeze.Ai with — from its own Stripe payment.
            'subscriptionPayment' => $this->subscriptionPayment($request->user()),
            // Who the company is — what was entered when it was set up.
            'company' => $this->companyDetails($request->user()),
            // Only the company's owner may correct its details.
            'canEditCompany' => $request->user()->company_id !== null
                && CompanyProfile::query()->whereKey($request->user()->company_id)->value('user_id') === $request->user()->id,
            'processors' => $processors,
            'paymentMethods' => $methods,
            'connectedProcessors' => $processors->filter(fn ($p) => $p['isConnected'])->values(),
            'billingSettings' => [
                'autoSendInvoices' => $billingSettings->auto_send_invoices,
                'includePaymentInstructions' => $billingSettings->include_payment_instructions,
                'sendPaymentReminders' => $billingSettings->send_payment_reminders,
                'applyLateFeesAutomatically' => $billingSettings->apply_late_fees_automatically,
                'defaultPaymentTerms' => $billingSettings->default_payment_terms,
                'defaultCurrency' => $billingSettings->default_currency,
            ],
            'paymentTermsOptions' => collect(config('payments.payment_terms'))
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values(),
            'currencyOptions' => collect(config('payments.currencies'))
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values(),
            'transactions' => [
                'data' => $transactions->through(fn (PaymentTransaction $transaction) => [
                    'id' => $transaction->id,
                    'date' => $transaction->occurred_at->toISOString(),
                    'description' => $transaction->description,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status,
                    'processorName' => $transaction->processor?->display_name ?? 'Manual',
                    'invoiceUrl' => $transaction->invoice ? route('invoices.show', $transaction->invoice, absolute: false) : null,
                ])->values(),
                'meta' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'total' => $transactions->total(),
                ],
            ],
            'can' => ['manage' => true],
        ]);
    }

    public function connectProcessor(Request $request, PaymentProcessor $processor): RedirectResponse
    {
        $this->authorizeManage($request);

        $fields = $this->processors->connectorFor($processor->key)->requiredCredentialFields();
        $rules = [];
        foreach (array_keys($fields) as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
        }
        $credentials = $request->validate($rules);

        $result = $this->processors->connect($processor, $credentials, $request->user());

        if ($result->success) {
            $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::CONNECTED);

            return back()->with('success', "{$processor->display_name}: {$result->message}");
        }

        return back()->withErrors(['credentials' => $result->message]);
    }

    public function testProcessor(Request $request, PaymentProcessor $processor): RedirectResponse
    {
        $this->authorizeManage($request);

        $result = $this->processors->test($processor);

        if (! $result->success) {
            $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::ERROR);
        }

        return back()->with($result->success ? 'success' : 'warning', "{$processor->display_name}: {$result->message}");
    }

    public function disconnectProcessor(Request $request, PaymentProcessor $processor): RedirectResponse
    {
        $this->authorizeManage($request);

        $this->processors->disconnect($processor);
        $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::DISCONNECTED);

        return back()->with('warning', "{$processor->display_name} was disconnected.");
    }

    public function storePaymentMethod(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'payment_processor_id' => ['required', 'integer', 'exists:payment_processors,id'],
            'brand' => ['required', 'string', 'max:40'],
            'last_four' => ['required', 'digits:4'],
            'exp_month' => ['required', 'integer', 'min:1', 'max:12'],
            'exp_year' => ['required', 'integer', 'min:'.now()->year, 'max:'.(now()->year + 20)],
            'make_default' => ['sometimes', 'boolean'],
        ]);

        $processor = PaymentProcessor::findOrFail($data['payment_processor_id']);

        if (! $processor->isConnected()) {
            return back()->withErrors(['payment_processor_id' => 'That processor is not connected — connect it before adding a payment method.']);
        }

        $method = $this->methods->create($processor, $data, $request->user());

        return back()->with('success', "{$method->label()} was added.");
    }

    public function setDefaultPaymentMethod(Request $request, PaymentMethod $method): RedirectResponse
    {
        $this->authorizeManage($request);

        $this->methods->makeDefault($method);

        return back()->with('success', "{$method->label()} is now the default payment method.");
    }

    public function destroyPaymentMethod(Request $request, PaymentMethod $method): RedirectResponse
    {
        $this->authorizeManage($request);

        $label = $method->label();
        $this->methods->delete($method);

        return back()->with('warning', "{$label} was removed.");
    }

    /**
     * Moves the company onto another plan.
     *
     * Nothing is charged here — the plan is what the company is on, and this changes
     * which. It is refused while the company uses more than the new plan holds, so
     * a downgrade never leaves it over a limit it did not choose.
     */
    public function changePlan(Request $request, SubscriptionSummary $summary): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('subscription.plans')))],
            'billing_cycle' => ['required', Rule::in(Subscription::CYCLES)],
        ]);

        $name = config("subscription.plans.{$data['plan']}.name");

        $blockers = $summary->blockersFor($data['plan'], $request->user());

        if ($blockers !== []) {
            return back()->withErrors([
                'plan' => "You can't move to {$name} yet: ".implode('; ', $blockers).'.',
            ]);
        }

        $subscription = Subscription::forUser($request->user());
        $cycleChanged = $subscription->billing_cycle !== $data['billing_cycle'];

        $subscription->update([
            'plan' => $data['plan'],
            'billing_cycle' => $data['billing_cycle'],
            // A different cycle length starts a fresh period from today.
            ...($cycleChanged
                ? ['renews_on' => $data['billing_cycle'] === 'yearly' ? now()->addYear() : now()->addMonth()]
                : []),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', "You're now on the {$name} plan.");
    }

    public function updateBillingSettings(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'auto_send_invoices' => ['required', 'boolean'],
            'include_payment_instructions' => ['required', 'boolean'],
            'send_payment_reminders' => ['required', 'boolean'],
            'apply_late_fees_automatically' => ['required', 'boolean'],
            'default_payment_terms' => ['required', Rule::in(array_keys(config('payments.payment_terms')))],
            'default_currency' => ['required', Rule::in(array_keys(config('payments.currencies')))],
        ]);

        BillingSetting::current()->update([...$data, 'updated_by' => $request->user()->id]);

        return back()->with('success', 'Billing settings saved.');
    }

    /**
     * The card and plan this account's subscription is paid with, or null when it
     * has not paid through Payment Setup.
     *
     * @return array<string, mixed>|null
     */
    private function subscriptionPayment(User $user): ?array
    {
        $subscription = Subscription::query()->where('user_id', $user->id)->first();
        $card = SubscriptionCard::query()->where('user_id', $user->id)->first();

        if (! $subscription || ! $card) {
            return null;
        }

        $quote = app(PlanPricing::class)->quote($subscription->plan, $subscription->billing_cycle);

        return [
            'planName' => config("subscription.plans.{$subscription->plan}.name"),
            'billingCycle' => $subscription->billing_cycle,
            'amount' => $quote['total'],
            'renewsOn' => $subscription->renews_on->toDateString(),
            'status' => $subscription->status,
            'viaStripe' => $subscription->stripe_subscription_id !== null,
            'card' => [
                'brand' => $card->brand,
                'lastFour' => $card->last_four,
                'expiry' => $card->expiry(),
                'holder' => $card->cardholder_name,
                'billingAddress' => collect([
                    $card->address_line1,
                    $card->address_line2,
                    collect([$card->city, trim("{$card->state} {$card->postal_code}")])->filter()->implode(', '),
                    $card->country,
                ])->filter()->implode(' · '),
            ],
        ];
    }

    /**
     * The company this account belongs to, as entered at setup, or null when it has
     * none (an account from before companies existed).
     *
     * @return array<string, mixed>|null
     */
    private function companyDetails(User $user): ?array
    {
        $company = $user->company_id === null ? null : CompanyProfile::query()->find($user->company_id);

        return $company === null ? null : [
            'name' => $company->name,
            'businessAddress' => $company->business_address,
            'primaryContact' => $company->primary_contact,
            'phone' => $company->phone,
            'email' => $company->email,
            'licenseNumber' => $company->license_number,
            'timezone' => str_replace('_', ' ', $company->timezone),
            'logoUrl' => $company->logoUrl(),
        ];
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(PaymentSettingsPolicy::class)->manage($request->user()), 403);
    }

    private function notifyOtherManagers(User $actor, PaymentProcessor $processor, string $reason): void
    {
        $managers = User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', ['project manager', 'admin', 'owner'])
            ->where('id', '!=', $actor->id)
            ->get();

        foreach ($managers as $manager) {
            $manager->notify(new PaymentProcessorStatusChanged($processor, $reason));
        }
    }
}
