<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The company's plan: what it is on, how much of it is used, and moving to another.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    private function settings(): TestResponse
    {
        return $this->actingAs($this->manager)->get('/settings');
    }

    public function test_the_company_starts_on_the_default_plan_renewing_next_month(): void
    {
        Carbon::setTestNow('2026-09-30');

        $this->settings()->assertInertia(fn ($page) => $page
            ->where('subscription.plan.key', 'growth')
            ->where('subscription.plan.name', 'Growth')
            ->where('subscription.status', 'active')
            ->where('subscription.billingCycle', 'monthly')
            ->where('subscription.renewsOn', '2026-10-30')
            ->where('subscription.seats.limit', 10)
            ->where('subscription.plan.price', 149)
            ->has('subscription.plan.features', 5)
            ->has('subscription.plans', 3));

        $this->assertSame(1, Subscription::count());
        Carbon::setTestNow();
    }

    public function test_the_usage_is_counted_from_the_real_tables(): void
    {
        foreach (['One', 'Two', 'Three'] as $name) {
            Project::create(['user_id' => $this->manager->id, 'name' => $name, 'client' => 'Acme', 'status' => 'draft']);
        }

        $this->settings()->assertInertia(function ($page) {
            $usage = collect($page->toArray()['props']['subscription']['usage'])->keyBy('key');

            $this->assertSame(3, $usage['projects']['used']);
            $this->assertSame(100, $usage['projects']['limit']);
            $this->assertSame(50, $usage['storage']['limit']);
            $this->assertSame('GB', $usage['storage']['unit']);
            $this->assertSame(0, $usage['ai_takeoffs']['used']);
        });
    }

    public function test_seats_are_the_people_who_can_sign_in(): void
    {
        User::factory()->create(['status' => User::STATUS_ACTIVE]);
        User::factory()->create(['status' => User::STATUS_REJECTED]);

        // The manager and one more: the rejected one has no seat.
        $this->settings()->assertInertia(fn ($page) => $page->where('subscription.seats.used', 2));
    }

    public function test_a_renewal_that_has_passed_rolls_forward_to_the_next(): void
    {
        Carbon::setTestNow('2026-09-30');
        Subscription::create([
            'plan' => 'growth', 'status' => 'active', 'billing_cycle' => 'monthly', 'renews_on' => '2026-07-15',
        ]);

        $this->settings()->assertInertia(fn ($page) => $page->where('subscription.renewsOn', '2026-10-15'));
        Carbon::setTestNow();
    }

    public function test_the_plan_can_be_changed_and_the_cycle_with_it(): void
    {
        $this->actingAs($this->manager)
            ->put('/settings/payment/subscription', ['plan' => 'enterprise', 'billing_cycle' => 'yearly'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $subscription = Subscription::sole();
        $this->assertSame('enterprise', $subscription->plan);
        $this->assertSame('yearly', $subscription->billing_cycle);
        // A yearly cycle starts a fresh year.
        $this->assertTrue($subscription->renews_on->gt(now()->addMonths(11)));
        $this->assertSame($this->manager->id, $subscription->updated_by);
    }

    public function test_a_plan_the_company_has_outgrown_is_refused(): void
    {
        // Four active people; Starter holds two.
        User::factory()->count(3)->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($this->manager)
            ->put('/settings/payment/subscription', ['plan' => 'starter', 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors('plan');

        $this->assertSame('growth', Subscription::current()->plan);
    }

    public function test_an_unknown_plan_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->put('/settings/payment/subscription', ['plan' => 'platinum', 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors('plan');
    }

    public function test_only_someone_who_manages_payments_can_change_the_plan(): void
    {
        $tech = User::factory()->create(['role' => 'Journeyman']);

        $this->actingAs($tech)
            ->put('/settings/payment/subscription', ['plan' => 'enterprise', 'billing_cycle' => 'monthly'])
            ->assertForbidden();

        $this->assertNotSame('enterprise', Subscription::current()->plan);
    }

    public function test_seats_are_measured_against_the_plans_user_limit(): void
    {
        User::factory()->count(2)->create(['status' => User::STATUS_ACTIVE, 'role' => 'Foreman']);

        $this->settings()->assertInertia(fn ($page) => $page
            ->where('subscription.seats.used', 3)
            ->where('subscription.seats.limit', 10));

        // Enterprise has no ceiling.
        Subscription::current()->update(['plan' => 'enterprise']);
        $this->settings()->assertInertia(fn ($page) => $page->where('subscription.seats.limit', null));
    }
}
