<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\BreezeBucksTransaction;
use App\Models\RewardCatalogItem;
use App\Models\RewardRule;
use App\Models\User;
use App\Notifications\BreezeBucksAwarded;
use App\Services\BreezeBucks\RewardRedemptionService;
use App\Services\BreezeBucks\RewardRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Breeze Bucks is paused: nothing earned, redeemed or sent, and its screens are gone. */
class BreezeBucksPausedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['breeze_bucks.enabled' => false]);
    }

    public function test_nothing_is_earned_and_no_notification_is_sent(): void
    {
        Notification::fake();
        RewardRule::ensureDefaults();
        $user = User::factory()->create(['role' => 'Electrician']);

        $transaction = app(RewardRuleService::class)->award($user, RewardRule::JOB_TASK_COMPLETED, 'job_task', 1);

        $this->assertNull($transaction);
        $this->assertSame(0, BreezeBucksTransaction::count());
        Notification::assertNothingSent();
    }

    public function test_the_notification_has_no_channel_while_paused(): void
    {
        $this->assertSame([], (new BreezeBucksAwarded(new BreezeBucksTransaction))->via(new User));
    }

    public function test_redeeming_is_refused(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $reward = RewardCatalogItem::create(['name' => 'Cap', 'points_required' => 10, 'is_active' => true]);

        $this->expectException(ValidationException::class);

        app(RewardRedemptionService::class)->redeem($user, $reward);
    }

    public function test_the_screens_answer_404(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($user)->get('/breeze-bucks')->assertNotFound();
        $this->actingAs($user)->get('/breeze-bucks/rewards')->assertNotFound();
        $this->actingAs($user)->post('/breeze-bucks/award', [])->assertNotFound();
    }

    public function test_existing_breeze_bucks_notifications_are_hidden_but_kept(): void
    {
        $user = User::factory()->create();
        AppNotification::create(['user_id' => $user->id, 'type' => 'breeze-bucks-earned', 'title' => 'Breeze Bucks earned', 'detail' => '+50 BB']);
        AppNotification::create(['user_id' => $user->id, 'type' => 'job-created', 'title' => 'Job created', 'detail' => 'A job was created.']);

        $this->assertSame(['job-created'], $user->appNotifications()->pluck('type')->all());
        $this->assertSame(1, AppNotification::where('user_id', $user->id)->whereNull('read_at')->count());

        config(['breeze_bucks.enabled' => true]);

        $this->assertSame(2, AppNotification::where('user_id', $user->id)->count());
    }

    public function test_switching_it_back_on_restores_earning(): void
    {
        config(['breeze_bucks.enabled' => true]);
        RewardRule::ensureDefaults();
        $user = User::factory()->create(['role' => 'Electrician']);

        $transaction = app(RewardRuleService::class)->award($user, RewardRule::JOB_TASK_COMPLETED, 'job_task', 1);

        $this->assertNotNull($transaction);
        $this->assertSame(1, BreezeBucksTransaction::count());
    }
}
