<?php

namespace Tests\Feature\Api;

use App\Models\AppNotification;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileScheduleAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_a_job_with_no_schedule_yet_returns_a_clear_empty_state_not_an_error(): void
    {
        $user = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.schedule', null)
            ->assertJsonPath('data.myTasks', []);
    }

    public function test_an_electrician_only_sees_their_own_tasks_in_the_schedule_response(): void
    {
        $job = $this->makeJob();
        // Built before this electrician's `TeamMember` exists, so the
        // builder's own auto-staffing of its default tasks (matched
        // against whichever crew already exist) cannot have assigned any
        // of them to her — the one assignment made explicitly below is
        // the only one that should show up as "hers".
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $totalTasks = $schedule->tasks()->count();
        $myTask = $schedule->tasks()->first();

        $user = User::factory()->create(['name' => 'Someone Else Entirely', 'role' => 'Electrician']);
        $member = TeamMember::create(['name' => $user->name, 'initials' => 'SE', 'role' => 'Electrician', 'user_id' => $user->id]);
        $job->assignments()->create(['role' => 'electrician', 'name' => $user->name, 'user_id' => $user->id, 'assigned_at' => now()]);
        $myTask->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/schedule")
            ->assertOk();

        $taskIds = array_column($response->json('data.myTasks'), 'id');
        $this->assertSame([$myTask->id], $taskIds);
        $this->assertLessThan($totalTasks, count($taskIds));
    }

    public function test_schedule_access_is_blocked_for_a_job_the_electrician_is_not_staffed_on(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/schedule")
            ->assertStatus(403);
    }

    public function test_notifications_index_returns_only_the_signed_in_users_own_notifications(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create();

        AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'Mine', 'detail' => '']);
        AppNotification::create(['user_id' => $someoneElse->id, 'type' => 'job-assigned', 'title' => 'Not mine', 'detail' => '']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/notifications')
            ->assertOk();

        $titles = array_column($response->json('data.notifications'), 'title');
        $this->assertSame(['Mine'], $titles);
        $this->assertSame(1, $response->json('data.unreadCount'));
    }

    public function test_a_user_cannot_mark_someone_elses_notification_read(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $owner = User::factory()->create();
        $notification = AppNotification::create(['user_id' => $owner->id, 'type' => 'job-assigned', 'title' => 'Not mine', 'detail' => '']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_a_notification_read_is_idempotent(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $notification = AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'Mine', 'detail' => '']);

        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $firstReadAt = $notification->fresh()->read_at;

        // A retried mark-read request must not error or change anything.
        $this->withHeaders($headers)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    public function test_mark_all_read_only_touches_this_users_own_notifications(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create();

        AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'Mine 1', 'detail' => '']);
        AppNotification::create(['user_id' => $user->id, 'type' => 'job-assigned', 'title' => 'Mine 2', 'detail' => '']);
        $othersNotification = AppNotification::create(['user_id' => $someoneElse->id, 'type' => 'job-assigned', 'title' => 'Not mine', 'detail' => '']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        $this->assertSame(0, AppNotification::where('user_id', $user->id)->whereNull('read_at')->count());
        $this->assertNull($othersNotification->fresh()->read_at);
    }
}
