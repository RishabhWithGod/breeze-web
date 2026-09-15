<?php

namespace Tests\Feature\Api;

use App\Events\JobStatusChanged;
use App\Models\Estimate;
use App\Models\EstimateItem;
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

    /** One estimate line grouped into a task at setup — what a supervisor's per-line checklist checks off. */
    private function makeEstimateItemOnTask(\App\Models\JobTask $task): EstimateItem
    {
        $estimate = Estimate::create([
            'number' => 'EST-'.random_int(1000, 9999),
            'client' => 'Test Client',
            'project' => 'Test Project',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);

        // Labor: the checklist a supervisor/foreman checks off is scoped to
        // labor lines only (`JobTaskWorkflowService::laborItems()`) — a
        // material line is never a checklist tick, it just rides along with
        // whichever labor line installs it.
        return EstimateItem::create([
            'estimate_id' => $estimate->id,
            'job_task_id' => $task->id,
            'category' => EstimateItem::CATEGORY_LABOR,
            'description' => 'Duplex outlet',
            'unit' => 'ea',
            'quantity' => 10,
            'unit_cost' => 5,
        ]);
    }

    /**
     * A mobile technician synced onto the crew register — the state
     * `TechnicianController::syncForemanRoster()` produces once a manager has
     * given them both a team and a role. Linked by `user_id`, exactly as
     * `ElectricianJobAccess` expects, with no `job_assignments`/
     * `job_task_assignments` row at all — the point of these tests is that
     * neither is needed.
     */
    private function makeMobileForeman(string $name = 'Chris'): array
    {
        $user = User::factory()->create([
            'name' => $name,
            'role' => 'Foreman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        // `user_id` is deliberately not in `Foreman::$fillable` (see
        // `TechnicianController::syncForemanRoster()`), so `create([...])`
        // would silently drop it — direct assignment is the real path.
        $foreman = new Foreman(['name' => $name, 'initials' => 'CH', 'role' => 'foreman']);
        $foreman->user_id = $user->id;
        $foreman->save();

        return [$user, $foreman];
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

    public function test_a_mobile_onboarded_foreman_only_sees_jobs_they_are_staffed_on(): void
    {
        // Same role strings a web-side manager can carry ('Foreman'), but
        // signed up from the mobile app — `ElectricianJobAccess` must not
        // treat this as the web manager's unrestricted view.
        $technician = User::factory()->create([
            'role' => 'Foreman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $member = TeamMember::create([
            'name' => $technician->name,
            'initials' => 'MT',
            'role' => 'Foreman',
            'user_id' => $technician->id,
        ]);
        $jobA = $this->makeJob(['name' => 'Job A']);
        $jobB = $this->makeJob(['name' => 'Job B']);
        $this->staffOnTask($jobA, $member);
        // $jobB has no staffing for this technician at all.

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson('/api/v1/jobs')
            ->assertOk();

        $ids = array_column($response->json('data.jobs'), 'id');
        $this->assertContains($jobA->id, $ids);
        $this->assertNotContains($jobB->id, $ids);
    }

    public function test_a_technician_named_as_a_jobs_foreman_can_access_it_with_no_other_staffing(): void
    {
        [$technician, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        // Deliberately nothing in job_assignments or job_task_assignments —
        // `job.foreman_id` alone, the register mechanic, must be enough.

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson('/api/v1/jobs')
            ->assertOk();

        $this->assertContains($job->id, array_column($response->json('data.jobs'), 'id'));

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();
    }

    public function test_the_jobs_foreman_field_reports_a_supervisors_real_role_not_a_hardcoded_foreman_label(): void
    {
        [$technician, $foreman] = $this->makeMobileForeman();
        $foreman->update(['role' => Foreman::ROLE_SUPERVISOR]);
        $job = $this->makeJob(['foreman_id' => $foreman->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();

        $response->assertJsonPath('data.foreman.role', 'Supervisor');
    }

    public function test_a_technician_named_as_a_tasks_foreman_or_supervisor_can_access_the_job_and_see_the_task(): void
    {
        [$technician, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: false);
        $schedule->tasks()->create([
            'job_id' => $job->id,
            'title' => 'Rough-in',
            'supervisor_id' => $foreman->id,
            'estimated_hours' => 4,
        ]);
        // Not the job's own foreman, not in job_assignments, not in
        // job_task_assignments — only named on one task, as its supervisor.

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson('/api/v1/jobs')
            ->assertOk();
        $this->assertContains($job->id, array_column($response->json('data.jobs'), 'id'));

        $schedule = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson("/api/v1/jobs/{$job->id}/schedule")
            ->assertOk();
        $this->assertContains('Rough-in', array_column($schedule->json('data.myTasks'), 'title'));
    }

    public function test_a_mobile_onboarded_foreman_cannot_view_a_job_they_are_not_staffed_on(): void
    {
        $technician = User::factory()->create([
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $job = $this->makeJob();
        // Deliberately not staffed on it.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertStatus(403);
    }

    public function test_changing_a_jobs_status_from_mobile_uses_the_real_job_model_method_and_broadcasts(): void
    {
        Event::fake([JobStatusChanged::class]);

        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'in-progress']);
        $this->staffOnTask($job, $member);

        // 'on-hold' rather than 'completed': this test is only about the
        // mechanism (real model method, real broadcast), not completion's
        // own "every task must be done first" gate, covered separately.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'on-hold'])
            ->assertOk()
            ->assertJsonPath('data.to', 'on-hold');

        $this->assertSame('on-hold', $job->fresh()->status);

        // The exact same event `Job::changeStatus()` fires for a web
        // change — proving mobile reused the model method rather than
        // writing the status column directly and skipping the broadcast.
        Event::assertDispatched(JobStatusChanged::class, fn (JobStatusChanged $e) => $e->job->id === $job->id && $e->to === 'on-hold');
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

    public function test_a_site_supervisor_can_override_the_status_of_a_task_they_are_not_personally_on(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        // Not staffed on this specific task at all — the point is that a
        // supervisor's authority does not depend on personally being on it.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        $this->assertSame('blocked', $task->fresh()->status);
    }

    public function test_reopening_a_completed_task_via_status_override_clears_the_completed_timestamp(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->forceFill(['status' => 'completed', 'completion_pct' => 100, 'completed_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $fresh = $task->fresh();
        $this->assertSame('in-progress', $fresh->status);
        $this->assertNull($fresh->completed_at);
    }

    public function test_a_foreman_not_on_a_task_and_not_a_planner_cannot_override_its_status(): void
    {
        [$user] = $this->makeElectrician('Someone Else Entirely');
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'blocked'])
            ->assertStatus(403);

        $this->assertNotSame('blocked', $task->fresh()->status);
    }

    public function test_a_stale_completion_percentage_self_corrects_on_read(): void
    {
        // The real bug this pins: a task whose stored `completion_pct` was
        // never actually derived from its checklist (a seeded demo value,
        // or a line checked before this feature computed anything) must not
        // keep showing a number that disagrees with what is really checked.
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $this->makeEstimateItemOnTask($task);
        $this->makeEstimateItemOnTask($task);
        // Neither line is checked, yet the stored percentage disagrees.
        $task->update(['completion_pct' => 25]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();

        $response->assertJsonPath('data.completionPct', 0);
        $this->assertSame(0, $task->fresh()->completion_pct);
    }

    public function test_a_stale_completion_percentage_self_corrects_on_the_job_task_list(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);
        $item->completed_at = now();
        $item->save();
        $this->makeEstimateItemOnTask($task);
        // 1 of 2 lines is actually checked — 50% — but the stored value says
        // otherwise.
        $task->update(['completion_pct' => 90]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/tasks")
            ->assertOk();

        // `staffOnTask()` builds a full realistic schedule with several
        // tasks — find this one specifically rather than assuming position.
        $found = collect($response->json('data.tasks'))->firstWhere('id', $task->id);
        $this->assertNotNull($found);
        $this->assertSame(50, $found['completionPct']);
        $this->assertSame(50, $task->fresh()->completion_pct);
    }

    public function test_estimate_items_appear_on_a_tasks_response(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.estimateItems.0.description', 'Duplex outlet')
            ->assertJsonPath('data.estimateItems.0.isCompleted', false);
    }

    public function test_an_electrician_can_check_off_an_estimate_line_on_their_own_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.isCompleted', true);

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_checking_off_a_line_is_reversible(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);
        $item->completed_at = now();
        $item->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => false])
            ->assertOk()
            ->assertJsonPath('data.isCompleted', false);

        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_checking_off_a_line_moves_a_ready_task_to_in_progress_and_sets_its_percentage(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $task->update(['status' => 'ready', 'completion_pct' => 0]);
        $itemA = $this->makeEstimateItemOnTask($task);
        $this->makeEstimateItemOnTask($task);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$itemA->id}/completion", ['completed' => true])
            ->assertOk();

        $response->assertJsonPath('data.task.status', 'in-progress')
            ->assertJsonPath('data.task.completionPct', 50);

        $fresh = $task->fresh();
        $this->assertSame('in-progress', $fresh->status);
        $this->assertSame(50, $fresh->completion_pct);
    }

    public function test_unchecking_every_line_moves_an_in_progress_task_back_to_ready(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);
        $item->completed_at = now();
        $item->save();
        $task->update(['status' => 'in-progress', 'completion_pct' => 100]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => false])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'ready')
            ->assertJsonPath('data.task.completionPct', 0);

        $this->assertSame('ready', $task->fresh()->status);
    }

    public function test_checking_the_last_line_auto_completes_the_task(): void
    {
        Event::fake([\App\Events\JobTaskCompleted::class]);

        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $itemA = $this->makeEstimateItemOnTask($task);
        $itemB = $this->makeEstimateItemOnTask($task);
        $itemA->completed_at = now();
        $itemA->save();
        // itemB still unchecked — checking it off is the last one.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$itemB->id}/completion", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'completed')
            ->assertJsonPath('data.task.completionPct', 100);

        $fresh = $task->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);

        // The same real completion event an explicit tap on the task's own
        // circle fires — not a quieter, checklist-only side effect.
        Event::assertDispatched(\App\Events\JobTaskCompleted::class);
    }

    public function test_a_planner_can_uncheck_a_line_on_an_already_completed_task_reopening_it(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $itemA = $this->makeEstimateItemOnTask($task);
        $itemB = $this->makeEstimateItemOnTask($task);
        $itemA->completed_at = now();
        $itemA->save();
        $itemB->completed_at = now();
        $itemB->save();
        $task->forceFill(['status' => 'completed', 'completion_pct' => 100, 'completed_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->patchJson("/api/v1/estimate-items/{$itemB->id}/completion", ['completed' => false])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'in-progress');

        $fresh = $task->fresh();
        $this->assertSame('in-progress', $fresh->status);
        $this->assertSame(50, $fresh->completion_pct);
        $this->assertNull($fresh->completed_at);
    }

    public function test_a_foreman_cannot_uncheck_a_line_on_an_already_completed_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $itemA = $this->makeEstimateItemOnTask($task);
        $itemB = $this->makeEstimateItemOnTask($task);
        $itemA->completed_at = now();
        $itemA->save();
        $itemB->completed_at = now();
        $itemB->save();
        $task->forceFill(['status' => 'completed', 'completion_pct' => 100, 'completed_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$itemB->id}/completion", ['completed' => false])
            ->assertStatus(403);

        // Nothing moved — the line is still checked, the task still complete.
        $this->assertNotNull($itemB->fresh()->completed_at);
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_a_foreman_can_still_check_a_line_on_an_already_completed_task_back_on(): void
    {
        // Re-checking an already-checked line (or checking a line that was
        // somehow still unchecked on a completed task) is not "reopening"
        // anything — only unchecking is restricted to a planner.
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);
        $task->forceFill(['status' => 'completed', 'completion_pct' => 100, 'completed_at' => now()])->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_checking_off_the_only_line_on_a_blocked_task_completes_it(): void
    {
        // The completion rule is unconditional: reaching 100% completes the
        // task regardless of what status it was sitting in beforehand — a
        // supervisor who wants a blocked task to stay blocked despite its
        // checklist being done has `setStatus()` to correct it back.
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $task->update(['status' => 'blocked']);
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'completed');

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_checking_off_the_only_line_on_a_cancelled_task_does_not_reopen_it(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $task->update(['status' => 'cancelled']);
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.task.status', 'cancelled');

        $this->assertSame('cancelled', $task->fresh()->status);
    }

    public function test_an_electrician_cannot_check_off_a_line_on_a_task_they_are_not_assigned_to(): void
    {
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $item = $this->makeEstimateItemOnTask($task);

        [$user] = $this->makeElectrician('Someone Else Entirely');

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertStatus(403);

        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_a_site_supervisor_can_check_off_a_line_on_a_task_they_are_not_personally_on(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_a_foreman_named_on_their_own_task_can_check_off_its_lines(): void
    {
        // The real-world case a plain name-matched `job_task_assignments` row
        // never covers: 'Foreman' isn't a planner role, so this only passes
        // through `JobSchedulePolicy::isAssigned()` recognizing `foreman_id`.
        [$technician, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->update(['foreman_id' => $foreman->id]);
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk()
            ->assertJsonPath('data.isCompleted', true);

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_a_foreman_named_as_a_tasks_supervisor_can_check_off_its_lines(): void
    {
        [$technician, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->update(['supervisor_id' => $foreman->id]);
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_a_foreman_can_complete_their_own_task_when_named_only_via_foreman_id(): void
    {
        [$technician, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob();
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $task->update(['foreman_id' => $foreman->id]);
        // Deliberately no job_task_assignments row — the point is that the
        // foreman_id link alone is enough, matching how a mobile-onboarded
        // technician is actually staffed.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_a_task_cannot_be_completed_while_a_checklist_line_is_unchecked(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $this->makeEstimateItemOnTask($task);
        $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertStatus(422);

        $this->assertNotSame('completed', $task->fresh()->status);
    }

    public function test_a_task_can_be_completed_once_every_checklist_line_is_checked(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $itemA = $this->makeEstimateItemOnTask($task);
        $itemB = $this->makeEstimateItemOnTask($task);
        // `completed_at` is deliberately not in `$fillable` (see
        // `EstimateItemController::setCompletion()`), so `update([...])`
        // would silently drop it — direct assignment is the real path.
        $itemA->completed_at = now();
        $itemA->save();
        $itemB->completed_at = now();
        $itemB->save();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_a_task_with_no_checklist_lines_completes_normally(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        // Deliberately no estimate items — nothing to check off, so nothing
        // should ever block completing it.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_a_supervisors_status_override_to_completed_is_also_blocked_by_an_unchecked_line(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();
        $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'completed'])
            ->assertStatus(422);

        $this->assertNotSame('completed', $task->fresh()->status);
    }

    public function test_checking_off_a_line_with_no_task_is_not_found(): void
    {
        [$user] = $this->makeElectrician();
        $estimate = Estimate::create([
            'number' => 'EST-'.random_int(1000, 9999),
            'client' => 'Test Client',
            'project' => 'Test Project',
            'issued_on' => now()->toDateString(),
            'amount' => 100,
            'status' => 'draft',
        ]);
        $item = EstimateItem::create([
            'estimate_id' => $estimate->id,
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Unplanned line',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 5,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertStatus(404);
    }

    public function test_a_job_scheduled_for_the_future_cannot_be_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => now()->addDays(3)->toDateString()]);
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertStatus(422);

        $this->assertSame('scheduled', $job->fresh()->status);
    }

    public function test_a_job_scheduled_for_today_can_be_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => now()->toDateString()]);
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_a_job_whose_start_date_has_already_passed_can_be_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => now()->subDays(2)->toDateString()]);
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_a_job_with_no_start_date_can_still_be_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => null]);
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_completing_a_task_is_blocked_until_the_job_has_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => now()->addDays(1)->toDateString()]);
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/tasks/{$task->id}/complete")
            ->assertStatus(422);

        $this->assertNotSame('completed', $task->fresh()->status);
    }

    public function test_reporting_progress_is_blocked_until_the_job_has_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'planning', 'start_date' => null]);
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/tasks/{$task->id}/progress", ['completion_pct' => 50])
            ->assertStatus(422);
    }

    public function test_a_status_override_is_blocked_until_the_job_has_started(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id, 'status' => 'draft', 'start_date' => null]);
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $task = $schedule->tasks()->first();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'in-progress'])
            ->assertStatus(422);
    }

    public function test_checking_off_a_checklist_line_is_blocked_until_the_job_has_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'scheduled', 'start_date' => now()->addDays(1)->toDateString()]);
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertStatus(422);

        $this->assertNull($item->fresh()->completed_at);
    }

    public function test_task_actions_work_normally_once_the_job_has_actually_started(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob(['status' => 'in-progress', 'start_date' => now()->subDays(1)->toDateString()]);
        $this->staffOnTask($job, $member);
        $task = \App\Models\JobTask::where('job_id', $job->id)->first();
        $item = $this->makeEstimateItemOnTask($task);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->patchJson("/api/v1/estimate-items/{$item->id}/completion", ['completed' => true])
            ->assertOk();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_a_supervisor_cannot_start_a_job(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $foreman = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $foreman->user_id = $supervisor->id;
        $foreman->save();

        $job = $this->makeJob(['foreman_id' => $foreman->id, 'status' => 'scheduled', 'start_date' => now()->toDateString()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertStatus(403);

        $this->assertSame('scheduled', $job->fresh()->status);
    }

    public function test_a_foreman_can_start_a_job(): void
    {
        [$user, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob(['foreman_id' => $foreman->id, 'status' => 'scheduled', 'start_date' => now()->toDateString()]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    /** Stamps `$foreman` as the foreman on every task `ScheduleBuilder` seeded for this job. */
    private function assignForemanToAllTasks(Job $job, Foreman $foreman): void
    {
        \App\Models\JobTask::where('job_id', $job->id)->update(['foreman_id' => $foreman->id]);
    }

    public function test_a_jobs_show_response_carries_a_foremans_live_timer_in_crew_time(): void
    {
        [$user, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob();
        app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $this->assignForemanToAllTasks($job, $foreman);

        \App\Models\TimerSession::create([
            'user_id' => $user->id,
            'job_id' => $job->id,
            'team_member_id' => null,
            'started_at' => now()->subMinutes(30),
            'accumulated_seconds' => 0,
            'status' => 'running',
            'billable' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();

        $response->assertJsonPath('data.crewTime.0.foremanName', 'Chris');
        $response->assertJsonPath('data.crewTime.0.status', 'running');
        $this->assertGreaterThanOrEqual(1795, $response->json('data.crewTime.0.liveElapsedSeconds'));
    }

    public function test_crew_time_is_empty_with_no_foreman_assigned_to_any_task(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.crewTime', []);
    }

    /**
     * A task's `foreman_id` can point at a supervisor's own register row
     * instead of a real foreman (see `Foreman`'s own doc comment) — a
     * supervisor's own clock must never surface in the crew's own time
     * breakdown mislabeled as a foreman's.
     */
    public function test_crew_time_excludes_a_task_lead_who_is_actually_a_supervisor(): void
    {
        $supervisor = User::factory()->create([
            'name' => 'Sam',
            'role' => 'Site Supervisor',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        $lead = new Foreman(['name' => 'Sam', 'initials' => 'SA', 'role' => 'supervisor']);
        $lead->user_id = $supervisor->id;
        $lead->save();

        $job = $this->makeJob();
        app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $this->assignForemanToAllTasks($job, $lead);

        \App\Models\TimerSession::create([
            'user_id' => $supervisor->id,
            'job_id' => $job->id,
            'team_member_id' => null,
            'started_at' => now(),
            'accumulated_seconds' => 0,
            'status' => 'running',
            'billable' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisor))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.crewTime', []);
    }

    /**
     * The scenario the user actually asked for: a supervisor overseeing a
     * job split across two foremen sees both of them, each with their own
     * time — one still running live, one only with hours already banked.
     */
    public function test_crew_time_lists_every_foreman_on_the_job_separately(): void
    {
        [$foremanAUser, $foremanA] = $this->makeMobileForeman('Robert');
        [$foremanBUser, $foremanB] = $this->makeMobileForeman('Priya');
        $job = $this->makeJob();

        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $tasks = $schedule->tasks()->orderBy('position')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());

        $half = intdiv($tasks->count(), 2);
        foreach ($tasks as $i => $task) {
            $task->foreman_id = $i < $half ? $foremanA->id : $foremanB->id;
            $task->save();
        }

        // Foreman A already logged 2h and is done for now — no active session.
        \App\Models\TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $foremanAUser->id,
            'date' => now()->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '10:00:00',
            'break_minutes' => 0,
            'hours' => 2,
            'source' => \App\Models\TimeEntry::SOURCE_MANUAL,
            'status' => \App\Models\TimeEntry::STATUS_DRAFT,
        ]);

        // Foreman B is currently clocked in, nothing banked yet.
        \App\Models\TimerSession::create([
            'user_id' => $foremanBUser->id,
            'job_id' => $job->id,
            'team_member_id' => null,
            'started_at' => now()->subMinutes(15),
            'accumulated_seconds' => 0,
            'status' => 'running',
            'billable' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk();

        $crew = collect($response->json('data.crewTime'))->keyBy('foremanName');

        $this->assertSame(7200, $crew['Robert']['totalSeconds']);
        $this->assertNull($crew['Robert']['status']);

        $this->assertSame(0, $crew['Priya']['totalSeconds']);
        $this->assertSame('running', $crew['Priya']['status']);
        $this->assertGreaterThanOrEqual(895, $crew['Priya']['liveElapsedSeconds']);
    }

    public function test_a_job_with_open_tasks_cannot_be_completed(): void
    {
        [$user, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        // Deliberately none of the seeded tasks are touched — all still open.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'tasks_incomplete');

        $this->assertNotSame('completed', $job->fresh()->status);
    }

    /**
     * The scenario the user asked for directly: two foremen split across a
     * job's tasks. Finishing your own slice submits *your own* portion for
     * review and a supervisor can approve it independently — neither your
     * submission nor the supervisor's approval of it is ever blocked by the
     * other foreman's still-open work. The job as a whole only actually
     * completes once every foreman's portion has been approved.
     */
    public function test_a_job_is_ready_for_review_once_every_task_across_every_foreman_is_done(): void
    {
        [$foremanAUser, $foremanA] = $this->makeMobileForeman('Robert');
        [$foremanBUser, $foremanB] = $this->makeMobileForeman('Priya');
        $job = $this->makeJob(['foreman_id' => $foremanA->id, 'estimated_hours' => 10]);

        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: true);
        $tasks = $schedule->tasks()->orderBy('position')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());

        $half = intdiv($tasks->count(), 2);
        foreach ($tasks as $i => $task) {
            $task->foreman_id = $i < $half ? $foremanA->id : $foremanB->id;
            $task->save();
        }

        foreach ($tasks->take($half) as $task) {
            $task->status = \App\Models\JobTask::STATUS_COMPLETED;
            $task->completed_at = now();
            $task->save();
        }

        // Foreman A's own tasks are all done — reflected for them...
        //
        // Laravel's `RequestGuard` caches whichever user it resolved for the
        // rest of the test process (real per-request handling never hits
        // this) — `forgetGuards()` before every switch between foreman A
        // and foreman B is what makes each call below actually authenticate
        // as the header it was sent with.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myTasksComplete', true);
        auth()->forgetGuards();

        // ...but foreman B's own tasks are not, from their own side...
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myTasksComplete', false);
        auth()->forgetGuards();

        // ...so foreman A can still submit their own half — B's open work
        // never blocks it — and the job stays open either way.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanAUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('message', 'Your tasks are marked ready for supervisor review.');
        auth()->forgetGuards();
        $this->assertNotSame('completed', $job->fresh()->status);

        [$supervisorUser, $supervisor] = $this->makeMobileForeman('Dana');
        $supervisor->update(['role' => Foreman::ROLE_SUPERVISOR]);
        $tasks->first()->update(['supervisor_id' => $supervisor->id]);

        // A supervisor can approve foreman A's submitted portion right now,
        // targeted at A specifically — B hasn't even finished, let alone
        // submitted, yet it doesn't stop A's own approval. The job as a
        // whole still isn't ready to close, since B is still unapproved.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/foremen/{$foremanA->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.fullyApproved', false);
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'not_ready_for_review');
        auth()->forgetGuards();
        $this->assertNotSame('completed', $job->fresh()->status);

        // Foreman B finishes and submits their own half too — now every
        // task on the job is done, from both foremen.
        foreach ($tasks->skip($half) as $task) {
            $task->status = \App\Models\JobTask::STATUS_COMPLETED;
            $task->completed_at = now();
            $task->save();
        }

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanBUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();
        auth()->forgetGuards();

        $job->refresh();
        $this->assertSame('in-progress', $job->status);

        // The supervisor approves B specifically too (A stays approved,
        // untouched by this second, separate tap) — with everyone approved,
        // the job itself finally closes.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/foremen/{$foremanB->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.fullyApproved', true);
        auth()->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();
        auth()->forgetGuards();

        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_my_tasks_complete_is_null_for_a_non_foreman(): void
    {
        [$user, $member] = $this->makeElectrician();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myTasksComplete', null);
    }

    public function test_my_tasks_complete_is_null_for_a_foreman_with_no_tasks_on_this_job(): void
    {
        [$user, $foreman] = $this->makeMobileForeman();
        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        // No tasks at all on this job — nothing personally assigned yet.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.myTasksComplete', null);
    }
}
