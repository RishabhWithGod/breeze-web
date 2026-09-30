<?php

namespace Tests\Feature;

use App\Models\PaymentProcessor;
use App\Models\Subscription;
use App\Models\SubscriptionCard;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanPricing;
use App\Services\Billing\StripeSubscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The third setup step: pick a plan and pay for it on Stripe.
 */
class PaymentSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.secret' => 'sk_test_platform']);
    }

    private function signUpThroughTerms(): User
    {
        $this->post('/signup', [
            'name' => 'New Owner',
            'email' => 'owner@example.com',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
        ]);
        $this->post(route('company.setup.store'), [
            'name' => 'Volt & Co', 'business_address' => '12 Main St', 'primary_contact' => 'Alex Morgan',
            'phone' => '(512) 555-0142', 'email' => 'office@volt.test', 'timezone' => 'America/Chicago',
        ]);
        $this->post(route('terms.store'), ['accept_terms' => true, 'accept_privacy' => true, 'signer_name' => 'New Owner'])
            ->assertRedirect(route('payment.setup.create'));

        return User::where('email', 'owner@example.com')->firstOrFail();
    }

    /** What Stripe returns for a session that was paid. */
    private function paidSession(User $user, array $overrides = []): array
    {
        return [
            'id' => 'cs_test_paid',
            'status' => 'complete',
            'payment_status' => 'paid',
            'client_reference_id' => (string) $user->id,
            'customer' => 'cus_123',
            'metadata' => ['user_id' => (string) $user->id, 'plan' => 'growth', 'cycle' => 'yearly'],
            'customer_details' => [
                'name' => 'Alex Morgan',
                'address' => ['line1' => '123 Construction Ave', 'line2' => null, 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
            ],
            'subscription' => [
                'id' => 'sub_123',
                'latest_invoice' => ['hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/test_123'],
                'current_period_end' => now()->addYear()->timestamp,
                'default_payment_method' => ['card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 9, 'exp_year' => 2028]],
            ],
            ...$overrides,
        ];
    }

    public function test_a_plan_is_one_fixed_price_with_the_annual_discount_taken_off(): void
    {
        $pricing = new PlanPricing;

        // Growth for a year: 149 x 12 = 1,788.00, less 20%.
        $yearly = $pricing->quote('growth', 'yearly');
        $this->assertSame(178800, $yearly['subtotal']);
        $this->assertSame(35760, $yearly['annualDiscount']);
        $this->assertSame(143040, $yearly['total']);

        $monthly = $pricing->quote('growth', 'monthly');
        $this->assertSame(14900, $monthly['total']);
        $this->assertSame(0, $monthly['annualDiscount']);
    }

    public function test_enterprise_is_priced_not_custom(): void
    {
        $this->assertSame(24900, (new PlanPricing)->quote('enterprise', 'monthly')['total']);
        $this->assertNull(config('subscription.plans.enterprise.max_users'));
    }

    public function test_the_screen_is_reached_after_the_terms_and_offers_the_plans(): void
    {
        $this->signUpThroughTerms();

        $this->get(route('home'))->assertRedirect(route('payment.setup.create'));
        $this->get(route('payment.setup.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PaymentSetup')
                ->has('plans', 3)
                ->where('annualDiscountPercent', 20)
                ->where('stripeReady', true)
                ->where('cancelled', false)
                ->where('plans.2.price', 249)
                ->where('plans.2.maxUsers', null)
                ->where('plans.1.maxUsers', 10));

        $this->get(route('payment.setup.create', ['cancelled' => 1]))
            ->assertInertia(fn ($page) => $page->where('cancelled', true));
    }

    public function test_activating_sends_the_person_to_stripe_with_the_right_price(): void
    {
        $user = $this->signUpThroughTerms();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_us']),
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
            ]),
        ]);

        $this->post(route('payment.setup.store'), ['plan' => 'growth', 'billing_cycle' => 'yearly'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');

        // The customer Stripe is told about starts in the United States.
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.stripe.com/v1/customers'
            && $request->data()['address']['country'] === 'US'
            && $request->data()['email'] === 'owner@example.com');

        Http::assertSent(function (Request $request) use ($user) {
            $body = $request->data();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Authorization', 'Bearer sk_test_platform')
                && $body['mode'] === 'subscription'
                && $body['client_reference_id'] === (string) $user->id
                && $body['customer'] === 'cus_us'
                && $body['adaptive_pricing']['enabled'] === 'false'
                && $body['line_items'][0]['price_data']['currency'] === 'usd'
                && $body['line_items'][0]['price_data']['unit_amount'] === 143040
                && $body['line_items'][0]['price_data']['recurring']['interval'] === 'year'
                && $body['metadata']['plan'] === 'growth';
        });

        // Nothing is recorded until Stripe says it was paid.
        $this->assertSame(0, Subscription::whereNotNull('user_id')->count());
        $this->assertTrue(auth()->user()->needs_payment_setup);
    }

    public function test_the_price_comes_from_the_server_not_the_browser(): void
    {
        $this->signUpThroughTerms();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_us']),
            'api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/x']),
        ]);

        $this->post(route('payment.setup.store'), ['plan' => 'starter', 'billing_cycle' => 'monthly', 'amount' => 1, 'total' => 1]);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/checkout/sessions')
            && $request->data()['line_items'][0]['price_data']['unit_amount'] === 4900);
    }

    public function test_an_unknown_plan_or_cycle_is_refused_before_stripe_is_called(): void
    {
        $this->signUpThroughTerms();
        Http::fake();

        $this->post(route('payment.setup.store'), ['plan' => 'platinum', 'billing_cycle' => 'monthly'])->assertSessionHasErrors('plan');
        $this->post(route('payment.setup.store'), ['plan' => 'growth', 'billing_cycle' => 'weekly'])->assertSessionHasErrors('billing_cycle');

        Http::assertNothingSent();
    }

    public function test_a_stripe_failure_is_reported_and_nothing_is_recorded(): void
    {
        $this->signUpThroughTerms();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);

        $this->post(route('payment.setup.store'), ['plan' => 'growth', 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors('plan');

        $this->assertSame(0, Subscription::whereNotNull('user_id')->count());
    }

    public function test_without_a_stripe_key_it_says_so_and_calls_nothing(): void
    {
        $this->signUpThroughTerms();
        config(['services.stripe.secret' => null]);
        Http::fake();

        $this->get(route('payment.setup.create'))->assertInertia(fn ($page) => $page->where('stripeReady', false));
        $this->post(route('payment.setup.store'), ['plan' => 'growth', 'billing_cycle' => 'monthly'])->assertSessionHasErrors('plan');

        Http::assertNothingSent();
    }

    public function test_a_customers_own_stripe_key_is_never_used_in_production(): void
    {
        config(['services.stripe.secret' => null]);
        $processor = PaymentProcessor::firstOrNew(['key' => 'stripe']);
        $processor->forceFill(['display_name' => 'Stripe', 'status' => 'active', 'credentials' => ['secret_key' => 'sk_test_customer']])->save();

        $service = app(StripeSubscriptions::class);

        // Locally the connected test key stands in...
        $this->assertSame('sk_test_customer', $service->secretKey());

        // ...but in production only the configured key counts.
        app()->detectEnvironment(fn () => 'production');
        $this->assertNull($service->secretKey());
        config(['services.stripe.secret' => 'sk_live_platform']);
        $this->assertSame('sk_live_platform', $service->secretKey());
    }

    public function test_coming_back_from_a_paid_session_records_the_subscription_and_card(): void
    {
        $user = $this->signUpThroughTerms();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_paid*' => Http::response($this->paidSession($user))]);

        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']))->assertRedirect(route('payment.setup.confirmed'));

        $subscription = Subscription::where('user_id', $user->id)->sole();
        $this->assertSame('growth', $subscription->plan);
        $this->assertSame('yearly', $subscription->billing_cycle);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('cus_123', $subscription->stripe_customer_id);
        $this->assertSame('sub_123', $subscription->stripe_subscription_id);
        $this->assertTrue($subscription->renews_on->isSameDay(now()->addYear()));

        $card = SubscriptionCard::where('user_id', $user->id)->sole();
        $this->assertSame('Visa ending in 4242', $card->label());
        $this->assertSame('09/2028', $card->expiry());
        $this->assertSame('Austin', $card->city);
        $this->assertNull($card->address_line2);

        $this->assertMatchesRegularExpression('/^BMA-\d{6}-[0-9A-F]{4}$/', $subscription->confirmation_number);
        $this->assertSame('https://invoice.stripe.com/i/acct_1/test_123', $subscription->receipt_url);

        $this->assertFalse(auth()->user()->needs_payment_setup);
        $this->get(route('home'))->assertOk();
        $this->get(route('payment.setup.create'))->assertRedirect(route('home'));
    }

    public function test_the_confirmation_screen_shows_what_was_bought(): void
    {
        $user = $this->signUpThroughTerms();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_paid*' => Http::response($this->paidSession($user))]);
        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']));
        $subscription = Subscription::where('user_id', $user->id)->sole();

        $this->get(route('payment.setup.confirmed'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SubscriptionConfirmed')
                ->where('plan.name', 'Growth')
                ->where('plan.status', 'active')
                ->where('confirmationNumber', $subscription->confirmation_number)
                ->where('billingCycle', 'yearly')
                ->where('nextBillingDate', $subscription->renews_on->toDateString())
                ->where('administrator.name', 'New Owner')
                ->where('administrator.email', 'owner@example.com')
                ->where('receiptUrl', 'https://invoice.stripe.com/i/acct_1/test_123'));

        // Reloading it is fine; confirming the same session again keeps the same reference.
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_paid*' => Http::response($this->paidSession($user))]);
        $this->get(route('payment.setup.confirmed'))->assertOk();

        // But it is not a page for next week.
        $this->travel(2)->hours();
        $this->get(route('payment.setup.confirmed'))->assertRedirect(route('home'));
    }

    public function test_there_is_nothing_to_confirm_before_paying(): void
    {
        $this->signUpThroughTerms();

        $this->get(route('payment.setup.confirmed'))->assertRedirect(route('home'));
    }

    public function test_an_older_account_with_no_subscription_of_its_own_goes_home(): void
    {
        $this->actingAs(User::factory()->create())->get(route('payment.setup.confirmed'))->assertRedirect(route('home'));
    }

    public function test_settings_shows_the_card_and_plan_it_was_paid_with(): void
    {
        $user = $this->signUpThroughTerms();
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_test_paid*' => Http::response($this->paidSession($user))]);
        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']));

        $this->actingAs($user->fresh())->get('/settings')->assertInertia(fn ($page) => $page
            ->where('subscription.plan.key', 'growth')
            ->where('subscriptionPayment.planName', 'Growth')
            ->where('subscriptionPayment.amount', 143040)
            ->where('subscriptionPayment.viaStripe', true)
            ->where('subscriptionPayment.card.brand', 'Visa')
            ->where('subscriptionPayment.card.lastFour', '4242')
            ->where('subscriptionPayment.card.expiry', '09/2028'));
    }

    public function test_a_session_that_was_not_paid_or_is_not_theirs_is_refused(): void
    {
        $user = $this->signUpThroughTerms();

        Http::fake(['api.stripe.com/*' => Http::response($this->paidSession($user, ['payment_status' => 'unpaid']))]);
        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']))->assertForbidden();

        Http::fake(['api.stripe.com/*' => Http::response($this->paidSession($user, ['client_reference_id' => '999']))]);
        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']))->assertForbidden();

        Http::fake(['api.stripe.com/*' => Http::response($this->paidSession($user, ['metadata' => ['plan' => 'platinum', 'cycle' => 'monthly']]))]);
        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_paid']))->assertForbidden();

        $this->assertSame(0, Subscription::whereNotNull('user_id')->count());
        $this->assertTrue(auth()->user()->needs_payment_setup);
    }

    public function test_a_made_up_session_address_is_not_taken_on_trust(): void
    {
        $this->signUpThroughTerms();
        Http::fake();

        $this->get(route('payment.setup.complete'))->assertNotFound();
        $this->get(route('payment.setup.complete', ['session_id' => 'anything']))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_session_stripe_cannot_find_leaves_the_person_on_the_step(): void
    {
        $this->signUpThroughTerms();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'No such session']], 404)]);

        $this->get(route('payment.setup.complete', ['session_id' => 'cs_test_gone']))
            ->assertRedirect(route('payment.setup.create'))
            ->assertSessionHas('warning');

        $this->assertTrue(auth()->user()->needs_payment_setup);
    }

    public function test_it_cannot_be_reached_before_the_earlier_steps(): void
    {
        $this->post('/signup', [
            'name' => 'New Owner', 'email' => 'owner@example.com',
            'password' => 'Str0ng-Passw0rd!x', 'password_confirmation' => 'Str0ng-Passw0rd!x',
        ]);
        Http::fake();

        $this->get(route('payment.setup.create'))->assertRedirect(route('company.setup.create'));
        $this->post(route('payment.setup.store'), ['plan' => 'growth', 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('company.setup.create'));

        Http::assertNothingSent();
    }

    public function test_the_terms_step_stays_open_from_here_so_back_works(): void
    {
        $this->signUpThroughTerms();

        $this->get(route('terms.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TermsConsent')->where('signerName', 'New Owner'));
    }

    public function test_an_older_account_is_never_sent_here(): void
    {
        $this->actingAs(User::factory()->create())->get(route('home'))->assertOk();
    }

    public function test_approving_a_technician_needs_room_on_the_plan(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        Subscription::create([
            'plan' => 'starter', 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => now()->addMonth(),
        ]);
        // Starter holds two: the manager and one more.
        User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $team = Team::create(['name' => 'Crew A']);

        $applicant = User::factory()->create(['role' => 'Journeyman']);
        $applicant->forceFill([
            'status' => User::STATUS_PENDING_APPROVAL, 'registration_source' => User::SOURCE_MOBILE,
        ])->save();

        // The plan's two places are taken.
        $this->actingAs($manager)
            ->post("/technicians/{$applicant->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertSessionHasErrors('team_id');
        $this->assertSame(User::STATUS_PENDING_APPROVAL, $applicant->fresh()->status);

        // Move up a plan and the same click goes through.
        Subscription::sole()->update(['plan' => 'growth']);
        $this->actingAs($manager)
            ->post("/technicians/{$applicant->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertSessionHasNoErrors();
        $this->assertSame(User::STATUS_ACTIVE, $applicant->fresh()->status);
    }
}
