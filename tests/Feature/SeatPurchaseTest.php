<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Foreman;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\SubscriptionSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Seats: what the company bought, who holds one, and buying more. A seat is a login on the plan —
 * held by an active person or by an invitation still open — and only paid-for seats are given.
 */
class SeatPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CompanyProfile $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'Owner']);
        $this->company = CompanyProfile::create([
            'user_id' => $this->owner->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $this->company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->owner->forceFill(['company_id' => $this->company->id])->save();
        $this->owner = $this->owner->fresh();

        $this->plan(seats: 2);
    }

    private function plan(int $seats, ?string $stripeSubscription = null): Subscription
    {
        return Subscription::updateOrCreate(['user_id' => $this->owner->id], [
            'plan' => 'growth', 'seats' => $seats, 'status' => 'active', 'billing_cycle' => 'monthly',
            'renews_on' => now()->addDays(15), 'stripe_subscription_id' => $stripeSubscription,
        ]);
    }

    private function person(string $role = 'Journeyman', string $name = 'Sam Rivera'): User
    {
        $user = User::factory()->create(['role' => $role, 'name' => $name, 'company_id' => $this->company->id]);

        return tap($user)->forceFill(['status' => User::STATUS_ACTIVE])->save() ? $user->fresh() : $user;
    }

    /** @return array<string, mixed> */
    private function newMember(string $email = 'dana@example.com'): array
    {
        $team = Team::withoutGlobalScopes()->where('company_id', $this->company->id)->first() ?? tap(new Team(['name' => 'Crew A']), function (Team $t) {
            $t->company_id = $this->company->id;
            $t->save();
        });

        return [
            'name' => 'Dana Wu', 'role' => 'journeyman', 'team_id' => $team->id, 'email' => $email,
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ];
    }

    public function test_a_project_manager_works_under_the_owners_plan_not_the_default(): void
    {
        $pm = $this->person('Project Manager', 'Pat PM');

        // The company bought 2 seats; the owner holds one, the manager another.
        $this->assertSame(2, Subscription::forUser($pm)->seats);
        $this->assertFalse(app(SubscriptionSummary::class)->hasSeatAvailable($pm));
        $this->assertSame(2, app(SubscriptionSummary::class)->build($pm)['seats']['limit']);
    }

    public function test_a_seat_promised_to_an_invitation_is_as_taken_as_one_in_use(): void
    {
        $this->plan(seats: 3);
        $this->person('Journeyman', 'Sam Rivera');

        // Owner + Sam = 2 of 3, so one is free...
        $this->assertTrue(app(SubscriptionSummary::class)->hasSeatAvailable($this->owner));

        // ...until an invitation holds it.
        \Illuminate\Support\Facades\Mail::fake();
        app(\App\Services\Company\TeamInvitations::class)->send($this->owner, [
            ['name' => 'Lee', 'email' => 'lee@example.com', 'role' => 'Journeyman'],
        ]);
        $this->assertSame(1, TeamInvitation::withoutGlobalScopes()->where('status', TeamInvitation::STATUS_PENDING)->count());
        $this->assertFalse(app(SubscriptionSummary::class)->hasSeatAvailable($this->owner));
    }

    public function test_adding_a_member_with_a_login_is_refused_when_no_seat_is_left_and_allowed_after_buying(): void
    {
        $this->person('Journeyman', 'Sam Rivera'); // owner + Sam = 2 of 2

        $this->actingAs($this->owner)->post(route('foremen.store'), $this->newMember())->assertSessionHasErrors('seats');
        $this->assertSame(0, User::where('email', 'dana@example.com')->count());
        $this->assertSame(0, Foreman::withoutGlobalScopes()->where('name', 'Dana Wu')->count());

        $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => 3])->assertSessionHas('success');

        $this->actingAs($this->owner)->post(route('foremen.store'), $this->newMember())->assertSessionHasNoErrors();
        $this->assertSame(1, User::where('email', 'dana@example.com')->count());
    }

    public function test_the_phone_is_held_to_the_same_limit(): void
    {
        $this->person('Journeyman', 'Sam Rivera'); // 2 of 2
        $token = $this->owner->createToken('t')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/team', $this->newMember())
            ->assertStatus(422)->assertJsonValidationErrors('seats');
        $this->assertSame(0, User::where('email', 'dana@example.com')->count());
    }

    public function test_buying_seats_adds_them_and_says_so(): void
    {
        $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => 5])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '5 seats added') && str_contains($message, 'now have 7 seats'));

        $this->assertSame(7, Subscription::forUser($this->owner)->seats);
        $this->assertSame($this->owner->id, Subscription::forUser($this->owner)->updated_by);

        // One seat reads as one.
        $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => 1])
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1 seat added'));
    }

    public function test_a_number_that_is_not_a_sensible_count_is_refused(): void
    {
        foreach ([0, -3, 101, 'many', 2.5, ''] as $bad) {
            $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => $bad])->assertSessionHasErrors('seats');
        }

        $this->assertSame(2, Subscription::forUser($this->owner)->seats);
    }

    public function test_only_the_people_who_run_billing_can_buy(): void
    {
        $this->actingAs($this->person('Foreman', 'Fran Foreman'))->post(route('settings.payment.seats.buy'), ['seats' => 5])->assertForbidden();
        $this->actingAs($this->person('Journeyman'))->post(route('settings.payment.seats.buy'), ['seats' => 5])->assertForbidden();

        $this->assertSame(2, Subscription::forUser($this->owner)->seats);

        // A project manager may; the plan they change is the company's one.
        $this->actingAs($this->person('Project Manager', 'Pat PM'))->post(route('settings.payment.seats.buy'), ['seats' => 2])->assertSessionHas('success');
        $this->assertSame(4, Subscription::forUser($this->owner)->seats);
    }

    public function test_a_paid_subscription_is_charged_through_stripe_and_seats_only_follow_the_payment(): void
    {
        config(['services.stripe.secret' => 'sk_test_demo']);
        $this->plan(seats: 2, stripeSubscription: 'sub_123');

        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'quantity' => 2]]]])
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'quantity' => 5]]], 'latest_invoice' => ['amount_paid' => 45000]]),
        ]);

        $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => 3])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '3 seats added') && str_contains($m, '450.00 USD was charged'));

        $this->assertSame(5, Subscription::forUser($this->owner)->seats);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/subscriptions/sub_123')
            && $request['items'][0]['id'] === 'si_1'
            && (int) $request['items'][0]['quantity'] === 5
            && $request['proration_behavior'] === 'always_invoice'
            && $request['payment_behavior'] === 'error_if_incomplete');
    }

    public function test_a_card_that_is_refused_gives_no_seats(): void
    {
        config(['services.stripe.secret' => 'sk_test_demo']);
        $this->plan(seats: 2, stripeSubscription: 'sub_123');

        Http::fake([
            'api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'quantity' => 2]]]])
                ->push(['error' => ['message' => 'Your card was declined.']], 402),
        ]);

        $this->actingAs($this->owner)->post(route('settings.payment.seats.buy'), ['seats' => 3])
            ->assertSessionHasErrors(['seats' => 'Your card was declined.']);

        $this->assertSame(2, Subscription::forUser($this->owner)->seats);
    }

    public function test_on_a_live_site_seats_are_never_given_without_a_payment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $purchases = app(\App\Services\Billing\SeatPurchases::class);

        // Nothing to charge, so nothing to give: the dialog says so, and a direct call refuses.
        $this->assertFalse($purchases->offer($this->owner)['canBuy']);
        $this->expectException(\RuntimeException::class);

        try {
            $purchases->buy($this->owner, 3);
        } finally {
            $this->assertSame(2, Subscription::forUser($this->owner)->seats);
        }
    }

    public function test_teams_says_who_may_buy_and_the_settings_page_has_the_price(): void
    {
        $this->actingAs($this->owner)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('canBuySeats', true)->where('seats.limit', 2));

        $this->actingAs($this->owner)->get(route('settings.payment.index'))->assertInertia(fn (Assert $page) => $page
            ->where('seatOffer.perSeatCents', 30000)
            ->where('seatOffer.seats', 2)
            ->where('seatOffer.cycle', 'monthly')
            ->where('seatOffer.canBuy', true)
            ->where('seatOffer.via', 'local'));
    }

    public function test_the_settings_page_still_opens_for_a_company_on_a_plan_that_no_longer_exists(): void
    {
        // The old unlimited "enterprise" plan is gone from the catalogue; accounts that bought it are not.
        $this->plan(seats: 10, stripeSubscription: 'sub_legacy')->update(['plan' => 'enterprise', 'billing_cycle' => 'yearly']);
        \App\Models\SubscriptionCard::create([
            'user_id' => $this->owner->id, 'brand' => 'Visa', 'last_four' => '4242', 'exp_month' => 12, 'exp_year' => 2030,
            'cardholder_name' => 'Olive Owner',
        ]);

        $this->actingAs($this->owner)->get(route('settings.payment.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('seatOffer.seats', 10)
            ->where('seatOffer.cycle', 'yearly')
            ->has('subscriptionPayment.amount')
            ->where('subscription.seats.limit', 10));
    }

    public function test_changing_plan_on_a_paid_subscription_is_made_at_stripe_and_then_recorded(): void
    {
        config(['services.stripe.secret' => 'sk_test_demo']);
        $this->plan(seats: 4, stripeSubscription: 'sub_123');

        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_new']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'quantity' => 4]]]])
                ->push(['id' => 'sub_123', 'current_period_end' => now()->addMonths(3)->timestamp, 'latest_invoice' => ['amount_paid' => 120000]]),
        ]);

        $this->actingAs($this->owner)->put(route('settings.payment.subscription.change'), ['plan' => 'platinum', 'billing_cycle' => 'monthly'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Breeze Platinum plan') && str_contains($m, '1,200.00 USD was charged'));

        $this->assertSame('platinum', Subscription::forUser($this->owner)->plan);
        $this->assertSame(4, Subscription::forUser($this->owner)->seats);
        $this->assertTrue(Subscription::forUser($this->owner)->renews_on->isSameDay(now()->addMonths(3)));

        // At Stripe: the same 4 seats, at the new plan's per-seat price ($500 = 50000 cents), invoiced now.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/subscriptions/sub_123') && $r->method() === 'POST'
            && $r['items'][0]['id'] === 'si_1'
            && (int) $r['items'][0]['quantity'] === 4
            && $r['items'][0]['price_data']['product'] === 'prod_new'
            && (int) $r['items'][0]['price_data']['unit_amount'] === 50000
            && $r['items'][0]['price_data']['recurring']['interval'] === 'month'
            && $r['proration_behavior'] === 'always_invoice'
            && $r['payment_behavior'] === 'error_if_incomplete');
    }

    public function test_a_refused_card_leaves_the_plan_where_it_was(): void
    {
        config(['services.stripe.secret' => 'sk_test_demo']);
        $this->plan(seats: 4, stripeSubscription: 'sub_123');

        Http::fake([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_new']),
            'api.stripe.com/v1/subscriptions/sub_123' => Http::sequence()
                ->push(['id' => 'sub_123', 'items' => ['data' => [['id' => 'si_1', 'quantity' => 4]]]])
                ->push(['error' => ['message' => 'Your card was declined.']], 402),
        ]);

        $this->actingAs($this->owner)->put(route('settings.payment.subscription.change'), ['plan' => 'platinum', 'billing_cycle' => 'yearly'])
            ->assertSessionHasErrors(['plan' => 'Your card was declined.']);

        $this->assertSame('growth', Subscription::forUser($this->owner)->plan);
        $this->assertSame('monthly', Subscription::forUser($this->owner)->billing_cycle);
    }

    public function test_an_account_on_a_retired_plan_can_move_onto_a_current_one(): void
    {
        $this->plan(seats: 10)->update(['plan' => 'enterprise', 'billing_cycle' => 'yearly']);

        $this->actingAs($this->owner)->put(route('settings.payment.subscription.change'), ['plan' => 'growth', 'billing_cycle' => 'yearly'])
            ->assertSessionHas('success');

        $this->assertSame('growth', Subscription::forUser($this->owner)->plan);
        $this->assertSame(10, Subscription::forUser($this->owner)->seats);
    }

    public function test_on_a_live_site_a_plan_is_never_changed_without_a_payment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);

        try {
            app(\App\Services\Billing\PlanChanges::class)->change($this->owner, 'platinum', 'monthly');
        } finally {
            $this->assertSame('growth', Subscription::forUser($this->owner)->plan);
        }
    }

    public function test_the_phone_changes_plan_through_the_same_rules(): void
    {
        $token = $this->owner->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/office/settings/subscription', ['plan' => 'platinum', 'billing_cycle' => 'monthly'])
            ->assertOk();
        $this->assertSame('platinum', Subscription::forUser($this->owner)->plan);
    }

    public function test_the_company_owner_can_do_everything_whatever_their_title_says(): void
    {
        // Titles are free text; ownership is not. This owner is down as an "Estimator".
        $this->owner->forceFill(['role' => 'Estimator'])->save();
        $owner = $this->owner->fresh();
        $this->assertTrue($owner->isCompanyOwner());

        $member = $this->person('Journeyman', 'Sam Rivera');
        $team = Team::withoutGlobalScopes()->where('company_id', $this->company->id)->first() ?? tap(new Team(['name' => 'Crew A']), function (Team $t) {
            $t->company_id = $this->company->id;
            $t->save();
        });
        $row = new Foreman(['name' => 'Sam Rivera', 'initials' => 'SR', 'team_id' => $team->id, 'role' => 'journeyman']);
        $row->company_id = $this->company->id;
        $row->user_id = $member->id;
        $row->save();

        // Money: the plan, the seats, the page they live on.
        $this->actingAs($owner)->get(route('settings.payment.index'))->assertOk();
        $this->actingAs($owner)->post(route('settings.payment.seats.buy'), ['seats' => 2])->assertSessionHas('success');
        $this->actingAs($owner)->put(route('settings.payment.subscription.change'), ['plan' => 'platinum', 'billing_cycle' => 'monthly'])->assertSessionHas('success');

        // People and work: the team, leave, estimates, roles.
        $this->actingAs($owner)->get(route('teams.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canBuySeats', true)->where('canManageLeave', true)->where('auth.user.isOwner', true));
        $this->actingAs($owner)->get(route('leave.index'))->assertOk();
        $this->actingAs($owner)->get(route('estimate-builder.index'))->assertOk();
        $this->actingAs($owner)->get(route('roles.show'))->assertOk();
        $this->actingAs($owner)->post(route('foremen.deactivate', $row))->assertSessionHas('success');
    }

    public function test_someone_who_does_not_own_the_company_gets_only_what_their_role_allows(): void
    {
        $estimator = $this->person('Estimator', 'Eli Estimator');
        $this->assertFalse($estimator->isCompanyOwner());

        $this->actingAs($estimator)->get(route('settings.payment.index'))->assertForbidden();
        $this->actingAs($estimator)->post(route('settings.payment.seats.buy'), ['seats' => 2])->assertForbidden();
        $this->actingAs($estimator)->get(route('leave.index'))->assertForbidden();
        $this->assertSame(2, Subscription::forUser($this->owner)->seats);
    }

    public function test_nobody_can_switch_the_company_owner_off(): void
    {
        $admin = $this->person('Admin', 'Ada Admin');
        $team = new Team(['name' => 'Crew A']);
        $team->company_id = $this->company->id;
        $team->save();
        $row = new Foreman(['name' => 'Olive Owner', 'initials' => 'OO', 'team_id' => $team->id, 'role' => 'foreman']);
        $row->company_id = $this->company->id;
        $row->user_id = $this->owner->id;
        $row->save();

        $this->actingAs($admin)->post(route('foremen.deactivate', $row))->assertSessionHas('warning');
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_plan_details_is_a_screen_of_its_own_with_the_plan_and_what_is_used(): void
    {
        $this->person('Journeyman', 'Sam Rivera');

        $this->actingAs($this->owner)->get(route('settings.plan.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PlanDetails')
            ->where('subscription.plan.name', 'Breeze Growth')
            ->where('subscription.seats.used', 2)
            ->where('subscription.seats.limit', 2)
            ->where('subscription.billingCycle', 'monthly')
            ->has('subscription.usage', 4)
            ->has('subscription.plans', 3)
            ->where('seatOffer.canBuy', true)
            ->where('seatOffer.priceKnown', true));
    }

    public function test_change_plan_is_a_screen_of_its_own_and_says_why_a_plan_is_out_of_reach(): void
    {
        // More projects than the cheapest plan holds: it stays on the screen, with the reason.
        foreach (range(1, 16) as $n) {
            \App\Models\Project::create(['user_id' => $this->owner->id, 'name' => "Job {$n}", 'client' => 'Acme', 'status' => 'draft']);
        }

        $this->actingAs($this->owner)->get(route('settings.plan.change'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ChangePlan')
            ->has('subscription.plans', 3)
            ->where('blockers.growth', [])
            ->where('blockers.platinum', [])
            ->has('blockers.basic', 1)
            ->where('seatOffer.via', 'local')
            ->where('origin', 'plan'));

        // Opened from Settings, it remembers, so Back goes there.
        $this->actingAs($this->owner)->get(route('settings.plan.change', ['from' => 'settings']))
            ->assertInertia(fn (Assert $page) => $page->where('origin', 'settings'));
    }

    public function test_changing_plan_from_its_screen_lands_on_plan_details_with_the_result(): void
    {
        $this->actingAs($this->owner)
            ->put(route('settings.payment.subscription.change'), ['plan' => 'platinum', 'billing_cycle' => 'yearly', 'return_to' => 'plan'])
            ->assertRedirect(route('settings.plan.show'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Breeze Platinum plan'));

        $this->assertSame('platinum', Subscription::forUser($this->owner)->plan);
        $this->assertSame('yearly', Subscription::forUser($this->owner)->billing_cycle);

        // From Settings it returns to Settings.
        $this->actingAs($this->owner)
            ->put(route('settings.payment.subscription.change'), ['plan' => 'growth', 'billing_cycle' => 'yearly', 'return_to' => 'settings'])
            ->assertRedirect(route('settings.payment.index', ['tab' => 'subscription']))
            ->assertSessionHas('success');

        // A refused change stays on the form, with the reason, rather than leaving it.
        $this->actingAs($this->owner)
            ->from(route('settings.plan.change'))
            ->put(route('settings.payment.subscription.change'), ['plan' => 'nonsense', 'billing_cycle' => 'monthly', 'return_to' => 'plan'])
            ->assertRedirect(route('settings.plan.change'))
            ->assertSessionHasErrors('plan');
    }

    public function test_both_plan_screens_open_for_an_account_on_a_retired_plan(): void
    {
        $this->plan(seats: 10)->update(['plan' => 'enterprise', 'billing_cycle' => 'yearly']);

        $this->actingAs($this->owner)->get(route('settings.plan.show'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('seatOffer.priceKnown', false)->where('subscription.seats.limit', 10));
        $this->actingAs($this->owner)->get(route('settings.plan.change'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ChangePlan')->has('blockers'));
    }

    public function test_only_billing_people_open_the_plan_screens_and_the_buy_popup_opens_in_place(): void
    {
        $this->actingAs($this->person('Estimator', 'Eli'))->get(route('settings.plan.show'))->assertForbidden();
        $this->actingAs($this->person('Estimator', 'Eli 2'))->get(route('settings.plan.change'))->assertForbidden();

        // The screens that open the Buy Seats popup carry what it needs, so no page change is needed.
        $this->actingAs($this->owner)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('seatOffer.canBuy', true)->where('seatOffer.perSeatCents', 30000));
        $this->actingAs($this->owner)->get(route('foremen.create'))->assertInertia(fn (Assert $page) => $page
            ->where('canBuySeats', true)->where('seatOffer.canBuy', true));

        // Someone who may not buy gets no offer to open.
        $foreman = $this->person('Foreman', 'Fran Foreman');
        $this->actingAs($foreman)->get(route('teams.index'))->assertInertia(fn (Assert $page) => $page
            ->where('canBuySeats', false)->where('seatOffer', null));
    }
}
