<?php

namespace Tests\Feature\Api;

use App\Events\JobStatusChanged;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Mobile job/task access — the IDOR boundary Phase 9 is really about.
 *
 * The web app has no per-job restriction (any signed-in user can open any
 * Job Detail page). Mobile is deliberately stricter: `ElectricianJobAccess`
 * only lets an electrician see jobs they are actually staffed on, either
 * through `job_assignments` or a task-level crew assignment.
 */
class MobileJobsAndTasksTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'job_type' => 'commercial',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeElectrician(string $name = 'Priya Raman'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'Electrician']);
        $member = TeamMember::create(['name' => $name, 'initials' => 'PR', 'role' => 'Electrician', 'user_id' => $user->id]);

        return [$user, $member];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function staffOnTask(Job $job, TeamMember $member): void
    {
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);
    }

    public function test_the_job_list_only_contains_jobs_the_electrician_is_staffed_on(): void
    {
        [$user, $member] = $this->makeElectrician();
        $jobA = $this->makeJob(['name' => 'Job A']);
        $jobB = $this->makeJob(['name' => 'Job B']);
        $this->staffOnTask($jobA, $member);
        // $jobB has no staffing for this electrician at all.

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/jobs')
            ->assertOk();

        $ids = array_column($response->json('data.jobs'), 'id');
        $this->assertContains($jobA->id, $ids);
        $this->assertNotContains($jobB->id, $ids);
    }

    public function test_an_electrician_can_view_a_job_they_are_staffed_on(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $job->id);
    }

    public function test_an_electrician_cannot_view_a_job_they_are_not_staffed_on(): void
    {
        [$user] = $this->makeElectrician();
        $job = $this->makeJob();
        // Deliberately not staffed on it.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_an_electrician_cannot_change_the_status_of_a_job_they_are_not_staffed_on(): void
    {
        [$user] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'in-progress']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_a_manager_role_sees_every_job_on_mobile_matching_web_access(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();
        // No staffing at all — managers are unrestricted on mobile too.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();
    }

    public function test_changing_a_jobs_status_from_mobile_uses_the_real_job_model_method_and_broadcasts(): void
    {
        Event::fake([JobStatusChanged::class]);

        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'in-progress']);
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('data.to', 'completed');

        $this->assertSame('completed', $job->fresh()->status);

        // The exact same event `Job::changeStatus()` fires for a web
        // change — proving mobile reused the model method rather than
        // writing the status column directly and skipping the broadcast.
        Event::assertDispatched(JobStatusChanged::class, fn (JobStatusChanged $e) => $e->job->id === $job->id && $e->to === 'completed');
    }

    public function test_an_invalid_status_value_is_rejected_with_422(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'not-a-real-status'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_task_list_is_blocked_for_a_job_the_electrician_cannot_access(): void
    {
        [$user] = $this->makeElectrician();
        $job = $this->makeJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/tasks")
            ->assertStatus(403);
    }

    public function test_an_electrician_can_complete_their_own_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete", ['actual_hours' => 3.5])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'completed');

        $this->assertSame('completed', $task->fresh()->status);
        $this->assertSame(100, $task->fresh()->completion_pct);
    }

    public function test_an_electrician_cannot_complete_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        // Schedule is built (and its default tasks auto-staffed from
        // whichever `TeamMember` rows already exist) *before* this
        // electrician exists at all, so the builder cannot have matched
        // them to anything — the only way they could pass the policy
        // check afterward is by name-matching an assignment that isn't
        // actually theirs.
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();

        [$user] = $this->makeElectrician('Someone Else Entirely');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertStatus(403);

        $this->assertNotSame('completed', $task->fresh()->status);
    }

    public function test_an_electrician_can_report_progress_on_their_own_task_without_closing_it(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/tasks/{$task->id}/progress", ['completion_pct' => 40])
            ->assertOk()
            ->assertJsonPath('data.completionPct', 40);

        $this->assertNotSame('completed', $response->json('data.status'));
        $this->assertSame(40, $task->fresh()->completion_pct);
    }

    public function test_progress_over_100_percent_is_rejected(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/tasks/{$task->id}/progress", ['completion_pct' => 140])
            ->assertStatus(422);
    }
}
