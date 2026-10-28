<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\CompanyProfile;
use App\Models\PaymentProcessor;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\SubscriptionCard;
use App\Models\User;
use App\Notifications\PaymentProcessorStatusChanged;
use App\Policies\PaymentSettingsPolicy;
use App\Services\Billing\PlanPricing;
use App\Services\Billing\SubscriptionSummary;
use App\Services\Payments\PaymentProcessorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Payment Settings for the app — mobile's counterpart to web's own
 * `PaymentSettingsController` ("Settings": Company, Payment, Subscription),
 * calling the same services so the two never disagree. Web's page is
 * trimmed to Stripe for now, so this only ever shows and acts on Stripe.
 *
 * The whole screen is the managers' (`PaymentSettingsPolicy`): it holds
 * processor credentials and billing defaults.
 */
class OfficePaymentSettingsController extends Controller
{
    use ApiResponses;

    private const PER_PAGE = 10;

    public function __construct(private readonly PaymentProcessorService $processors) {}

    public function show(Request $request, SubscriptionSummary $summary): JsonResponse
    {
        $this->authorizeManage($request);
        $user = $request->user();

        // Seeded as `not_connected` until someone connects real credentials — never assumed active.
        $stripe = PaymentProcessor::firstOrCreate(['key' => PaymentProcessor::STRIPE], [
            'display_name' => PaymentProcessor::DISPLAY_NAMES[PaymentProcessor::STRIPE],
            'status' => PaymentProcessor::STATUS_NOT_CONNECTED,
        ]);

        $billing = BillingSetting::current();

        return $this->ok([
            'subscription' => $summary->build($user),
            'subscriptionPayment' => $this->subscriptionPayment($user),
            'company' => $this->companyDetails($user),
            // Only the company's owner may correct these; the screen offers Edit to them alone.
            'canEditCompany' => $user->company_id !== null
                && \App\Models\CompanyProfile::query()->whereKey($user->company_id)->value('user_id') === $user->id,
            'processor' => $this->presentProcessor($stripe),
            'billingSettings' => [
                'autoSendInvoices' => $billing->auto_send_invoices,
                'includePaymentInstructions' => $billing->include_payment_instructions,
                'sendPaymentReminders' => $billing->send_payment_reminders,
                'applyLateFeesAutomatically' => $billing->apply_late_fees_automatically,
                'defaultPaymentTerms' => $billing->default_payment_terms,
                'defaultCurrency' => $billing->default_currency,
            ],
            'paymentTermsOptions' => collect(config('payments.payment_terms'))
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values(),
            'currencyOptions' => collect(config('payments.currencies'))
                ->map(fn ($label, $value) => ['label' => $label, 'value' => $value])->values(),
        ]);
    }

    /** The payment history, a page at a time. */
    public function transactions(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $page = PaymentTransaction::with(['invoice', 'processor'])
            ->latest('occurred_at')
            ->paginate(min((int) $request->integer('per_page', self::PER_PAGE), 50));

        return $this->ok([
            'transactions' => $page->getCollection()->map(fn (PaymentTransaction $t) => [
                'id' => $t->id,
                'date' => $t->occurred_at->toISOString(),
                'description' => $t->description,
                'amount' => (float) $t->amount,
                'status' => $t->status,
                'processorName' => $t->processor?->display_name ?? 'Manual',
                'invoiceId' => $t->invoice?->id,
            ])->all(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function connect(Request $request, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeStripe($request, $processor);

        $fields = $this->processors->connectorFor($processor->key)->requiredCredentialFields();
        $rules = [];
        foreach (array_keys($fields) as $field) {
            $rules[$field] = ['required', 'string', 'max:255'];
        }
        $credentials = $request->validate($rules);

        $result = $this->processors->connect($processor, $credentials, $request->user());

        if (! $result->success) {
            return $this->fail($result->message, 422, ['credentials' => [$result->message]]);
        }

        $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::CONNECTED);

        return $this->ok($this->presentProcessor($processor->refresh()), "{$processor->display_name}: {$result->message}");
    }

    public function test(Request $request, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeStripe($request, $processor);

        $result = $this->processors->test($processor);

        if (! $result->success) {
            $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::ERROR);
        }

        return $this->ok(
            ['ok' => $result->success, 'processor' => $this->presentProcessor($processor->refresh())],
            "{$processor->display_name}: {$result->message}",
        );
    }

    public function disconnect(Request $request, PaymentProcessor $processor): JsonResponse
    {
        $this->authorizeStripe($request, $processor);

        $this->processors->disconnect($processor);
        $this->notifyOtherManagers($request->user(), $processor, PaymentProcessorStatusChanged::DISCONNECTED);

        return $this->ok($this->presentProcessor($processor->refresh()), "{$processor->display_name} was disconnected.");
    }

    /** Web's `updateBillingSettings()` — the defaults every new invoice starts from. */
    public function updateBilling(Request $request): JsonResponse
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

        return $this->ok(null, 'Billing settings saved.');
    }

    /**
     * Web's `changePlan()`: a paid Stripe subscription is moved at Stripe (pro-rata charged or credited),
     * and a plan the company has already outgrown is refused with the reason.
     */
    public function changePlan(Request $request, SubscriptionSummary $summary): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('subscription.plans')))],
            'billing_cycle' => ['required', Rule::in(Subscription::CYCLES)],
        ]);

        $name = config("subscription.plans.{$data['plan']}.name");
        $blockers = $summary->blockersFor($data['plan'], $request->user());

        if ($blockers !== []) {
            return $this->fail("You can't move to {$name} yet: ".implode('; ', $blockers).'.', 422, [
                'plan' => ["You can't move to {$name} yet: ".implode('; ', $blockers).'.'],
            ]);
        }

        try {
            app(\App\Services\Billing\PlanChanges::class)->change($request->user(), $data['plan'], $data['billing_cycle']);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422, ['plan' => [$e->getMessage()]]);
        }

        return $this->ok($summary->build($request->user()), "You're now on the {$name} plan.");
    }

    /** @return array<string, mixed> */
    private function presentProcessor(PaymentProcessor $processor): array
    {
        return [
            'id' => $processor->id,
            'key' => $processor->key,
            'displayName' => $processor->display_name,
            'status' => $processor->status,
            'isConnected' => $processor->isConnected(),
            'connectedAt' => $processor->connected_at?->toISOString(),
            'lastTestedAt' => $processor->last_tested_at?->toISOString(),
            'lastError' => $processor->last_error,
            'credentialFields' => $this->processors->connectorFor($processor->key)->requiredCredentialFields(),
            'paymentsRecorded' => PaymentTransaction::query()->count(),
        ];
    }

    /** @return array<string, mixed>|null */
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

    /** @return array<string, mixed>|null */
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
        ];
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(app(PaymentSettingsPolicy::class)->manage($request->user()), 403, 'Only a manager can open payment settings.');
    }

    /** Stripe is the only processor the screen offers. */
    private function authorizeStripe(Request $request, PaymentProcessor $processor): void
    {
        $this->authorizeManage($request);
        abort_unless($processor->key === PaymentProcessor::STRIPE, 404);
    }

    private function notifyOtherManagers(User $actor, PaymentProcessor $processor, string $reason): void
    {
        User::query()
            ->whereRaw('lower(trim(role)) in (?, ?, ?)', ['project manager', 'admin', 'owner'])
            ->where('id', '!=', $actor->id)
            ->get()
            ->each(fn (User $manager) => $manager->notify(new PaymentProcessorStatusChanged($processor, $reason)));
    }
}
