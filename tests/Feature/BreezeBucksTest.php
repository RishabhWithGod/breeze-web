<?php

namespace Tests\Feature;

use App\Events\JobTaskCompleted;
use App\Events\TakeoffProcessed;
use App\Events\TimeEntryApproved;
use App\Listeners\AwardBreezeBucksForApprovedTimeEntry;
use App\Listeners\AwardBreezeBucksForCompletedTakeoff;
use App\Listeners\AwardBreezeBucksForCompletedTask;
use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\BreezeBucksTransaction;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\RewardCatalogItem;
use App\Models\RewardRule;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\BreezeBucks\BreezeBucksLedger;
use App\Services\BreezeBucks\RewardRedemptionService;
use App\Services\BreezeBucks\RewardRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Breeze Bucks: the balance is never stored — always `SUM(amount)` over
 * `breeze_bucks_transactions` — so every assertion here is really checking
 * that the ledger, not some parallel counter, is the source of truth.
 */
class BreezeBucksTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Electrician']);
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_a_new_user_has_zero_balance(): void
    {
        $this->actingAs($this->user)->get('/breeze-bucks')
            ->assertInertia(fn (Assert $page) => $page
                ->component('BreezeBucks')
                ->where('balance', 0)
                ->where('lifetimeEarned', 0)
                ->where('lifetimeRedeemed', 0));
    }

    public function test_awarding_points_increases_balance_and_lifetime_earned(): void
    {
        $ledger = app(BreezeBucksLedger::class);
        $ledger->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 100, 'first award');

        $this->assertSame(100, $ledger->balanceFor($this->user));
        $this->assertSame(100, $ledger->lifetimeEarnedFor($this->user));
        $this->assertSame(0, $ledger->lifetimeRedeemedFor($this->user));

        $ledger->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 200, 'second award');

        $this->assertSame(300, $ledger->balanceFor($this->user));
        $this->assertSame(300, $ledger->lifetimeEarnedFor($this->user));
    }

    public function test_redeeming_decreases_balance_and_increases_lifetime_redeemed(): void
    {
        $ledger = app(BreezeBucksLedger::class);
        $ledger->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 300, 'award');

        $reward = RewardCatalogItem::create(['name' => 'Gift Card', 'points_required' => 100, 'is_active' => true]);
        app(RewardRedemptionService::class)->redeem($this->user, $reward);

        $this->assertSame(200, $ledger->balanceFor($this->user));
        $this->assertSame(300, $ledger->lifetimeEarnedFor($this->user));
        $this->assertSame(100, $ledger->lifetimeRedeemedFor($this->user));
    }

    public function test_redeeming_more_than_the_balance_is_rejected(): void
    {
        $reward = RewardCatalogItem::create(['name' => 'Big Reward', 'points_required' => 500, 'is_active' => true]);

        $this->actingAs($this->user)
            ->post("/breeze-bucks/rewards/{$reward->id}/redeem")
            ->assertSessionHasErrors();

        $this->assertSame(0, app(BreezeBucksLedger::class)->balanceFor($this->user));
        $this->assertDatabaseCount('breeze_bucks_redemptions', 0);
    }

    public function test_a_second_redemption_fails_once_stock_or_balance_is_exhausted(): void
    {
        app(BreezeBucksLedger::class)->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 100, 'award');
        $reward = RewardCatalogItem::create(['name' => 'Limited Reward', 'points_required' => 100, 'stock' => 1, 'is_active' => true]);

        $this->actingAs($this->user)
            ->post("/breeze-bucks/rewards/{$reward->id}/redeem")
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post("/breeze-bucks/rewards/{$reward->id}/redeem")
            ->assertSessionHasErrors();

        $this->assertSame(1, BreezeBucksTransaction::where('user_id', $this->user->id)->where('type', BreezeBucksTransaction::TYPE_REDEEMED)->count());
        $this->assertSame(0, $reward->fresh()->stock);
    }

    public function test_a_user_cannot_see_another_users_balance_or_history(): void
    {
        $other = User::factory()->create();
        app(BreezeBucksLedger::class)->record($other, BreezeBucksTransaction::TYPE_EARNED, 500, 'other user award');

        $this->actingAs($this->user)->get('/breeze-bucks')
            ->assertInertia(fn (Assert $page) => $page->where('balance', 0));

        $this->actingAs($this->user)->get('/breeze-bucks/history')
            ->assertInertia(fn (Assert $page) => $page->where('history.data', []));
    }

    public function test_an_unauthorized_user_cannot_award_points(): void
    {
        $this->actingAs($this->user)->get('/breeze-bucks/award')->assertForbidden();

        $this->actingAs($this->user)->post('/breeze-bucks/award', [
            'user_id' => $this->manager->id,
            'amount' => 100,
            'reason' => 'trying to self-serve',
        ])->assertForbidden();

        $this->assertSame(0, app(BreezeBucksLedger::class)->balanceFor($this->manager));
    }

    public function test_an_authorized_manager_can_award_points(): void
    {
        $this->actingAs($this->manager)->post('/breeze-bucks/award', [
            'user_id' => $this->user->id,
            'amount' => 250,
            'reason' => 'Excellent work this week',
        ])->assertSessionHasNoErrors();

        $this->assertSame(250, app(BreezeBucksLedger::class)->balanceFor($this->user));
        $this->assertDatabaseHas('breeze_bucks_transactions', [
            'user_id' => $this->user->id,
            'type' => BreezeBucksTransaction::TYPE_BONUS,
            'amount' => 250,
            'created_by' => $this->manager->id,
        ]);
    }

    public function test_a_reversal_creates_a_new_transaction_without_touching_the_original(): void
    {
        $ledger = app(BreezeBucksLedger::class);
        $original = $ledger->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 100, 'original award');

        $ledger->record($this->user, BreezeBucksTransaction::TYPE_REVERSAL, -100, 'reversing award', 'breeze_bucks_transaction', $original->id);

        $this->assertDatabaseHas('breeze_bucks_transactions', ['id' => $original->id, 'amount' => 100]);
        $this->assertSame(0, $ledger->balanceFor($this->user));
        $this->assertSame(2, BreezeBucksTransaction::where('user_id', $this->user->id)->count());
    }

    public function test_history_pagination_reflects_real_transaction_count(): void
    {
        $ledger = app(BreezeBucksLedger::class);
        for ($i = 0; $i < 15; $i++) {
            $ledger->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 10, "award {$i}");
        }

        $this->actingAs($this->user)->get('/breeze-bucks/history')
            ->assertInertia(fn (Assert $page) => $page
                ->has('history.data', 10)
                ->where('history.meta.total', 15)
                ->where('history.meta.last_page', 2));
    }

    public function test_inactive_rewards_are_hidden_from_a_normal_user_but_visible_to_an_admin(): void
    {
        $admin = User::factory()->create(['role' => 'Admin']);
        RewardCatalogItem::create(['name' => 'Active Reward', 'points_required' => 50, 'is_active' => true]);
        RewardCatalogItem::create(['name' => 'Retired Reward', 'points_required' => 50, 'is_active' => false]);

        $this->actingAs($this->user)->get('/breeze-bucks/rewards')
            ->assertInertia(fn (Assert $page) => $page->has('rewards', 1));

        $this->actingAs($admin)->get('/breeze-bucks/rewards')
            ->assertInertia(fn (Assert $page) => $page->has('rewards', 2));
    }

    public function test_earning_points_generates_a_real_notification(): void
    {
        RewardRule::create(['event_type' => 'test_event', 'points' => 40, 'enabled' => true, 'description' => 'Test award']);
        app(RewardRuleService::class)->award($this->user, 'test_event');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id,
            'type' => 'breeze-bucks-earned',
        ]);
    }

    public function test_redeeming_generates_a_real_notification(): void
    {
        app(BreezeBucksLedger::class)->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 100, 'award');
        $reward = RewardCatalogItem::create(['name' => 'Gift Card', 'points_required' => 100, 'is_active' => true]);

        app(RewardRedemptionService::class)->redeem($this->user, $reward);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id,
            'type' => 'breeze-bucks-redeemed',
        ]);
    }

    public function test_balance_is_correct_on_a_fresh_request_and_after_logout_login(): void
    {
        app(BreezeBucksLedger::class)->record($this->user, BreezeBucksTransaction::TYPE_EARNED, 175, 'award');

        $this->actingAs($this->user)->get('/breeze-bucks')
            ->assertInertia(fn (Assert $page) => $page->where('balance', 175));

        auth()->guard('web')->logout();

        $this->actingAs($this->user)->get('/breeze-bucks')
            ->assertInertia(fn (Assert $page) => $page->where('balance', 175));
    }

    public function test_a_disabled_reward_rule_awards_nothing(): void
    {
        RewardRule::create(['event_type' => 'disabled_event', 'points' => 999, 'enabled' => false, 'description' => 'Disabled']);

        $result = app(RewardRuleService::class)->award($this->user, 'disabled_event');

        $this->assertNull($result);
        $this->assertSame(0, app(BreezeBucksLedger::class)->balanceFor($this->user));
    }

    public function test_an_approved_time_entry_awards_the_configured_points_to_the_employee(): void
    {
        RewardRule::ensureDefaults();

        $job = Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);
        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $this->user->id,
            'date' => '2026-08-10',
            'hours' => 4,
            'status' => TimeEntry::STATUS_APPROVED,
            'source' => 'manual',
        ]);

        (new AwardBreezeBucksForApprovedTimeEntry(app(RewardRuleService::class)))->handle(new TimeEntryApproved($entry));

        $this->assertSame(50, app(BreezeBucksLedger::class)->balanceFor($this->user));
        $this->assertDatabaseHas('breeze_bucks_transactions', [
            'user_id' => $this->user->id,
            'source_type' => 'time_entry',
            'source_id' => $entry->id,
        ]);
    }

    public function test_a_completed_task_awards_the_configured_points_to_whoever_completed_it(): void
    {
        RewardRule::ensureDefaults();

        $job = Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
        ]);
        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        $task = JobTask::create(['job_schedule_id' => $schedule->id, 'job_id' => $job->id, 'title' => 'Site survey', 'estimated_hours' => 4]);

        (new AwardBreezeBucksForCompletedTask(app(RewardRuleService::class)))->handle(new JobTaskCompleted($task, $this->user));

        $this->assertSame(25, app(BreezeBucksLedger::class)->balanceFor($this->user));
    }

    public function test_a_completed_ai_takeoff_awards_the_project_owner(): void
    {
        RewardRule::ensureDefaults();

        $project = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Test Project',
            'client' => 'Test Client',
            'status' => 'processing',
        ]);
        $aiJob = AiJob::create(['project_id' => $project->id, 'user_id' => $this->user->id, 'status' => 'completed']);
        $result = AiResult::create(['ai_job_id' => $aiJob->id, 'project_id' => $project->id, 'original_payload' => []]);

        (new AwardBreezeBucksForCompletedTakeoff(app(RewardRuleService::class)))->handle(new TakeoffProcessed($result));

        $this->assertSame(100, app(BreezeBucksLedger::class)->balanceFor($this->user));
    }

    public function test_manage_catalog_actions_are_admin_only(): void
    {
        $this->actingAs($this->manager)->post('/breeze-bucks/rewards', [
            'name' => 'New Reward', 'points_required' => 100,
        ])->assertForbidden();

        $admin = User::factory()->create(['role' => 'Admin']);
        $this->actingAs($admin)->post('/breeze-bucks/rewards', [
            'name' => 'New Reward', 'points_required' => 100,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reward_catalog_items', ['name' => 'New Reward']);
    }
}
