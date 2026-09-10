<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\User;
use App\Notifications\JobCompleted;
use App\Notifications\JobStarted;
use App\Notifications\TaskScheduleChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Every notification this session added to the job/task lifecycle:
 * assigning a foreman/supervisor to a task, starting a job, and a
 * supervisor's final completion. `JobReviewStatusChanged` (submit for
 * review / sent back) is covered separately in
 * `Api\MobileJobCompletionWorkflowTest` — this file is everything added
 * alongside it.
 */
class JobNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->planner = User::factory()->create(['role' => 'Project Manager']);
    }

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Header Foreman', 'initials' => 'HF'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'job_type' => 'commercial',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    /** A crew-register row with a real linked account — only these can be notified. */
    private function makeForeman(string $name, string $role = Foreman::ROLE_FOREMAN): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => ucfirst($role)]);
        $foreman = new Foreman(['name' => $name, 'initials' => strtoupper(substr($name, 0, 2)), 'role' => $role]);
        $foreman->user_id = $user->id;
        $foreman->save();

        return [$user, $foreman];
    }

    /** The mobile API is Sanctum-guarded — `actingAs()` doesn't authenticate it, a real token does. */
    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /** @var array<int, JobSchedule> keyed by job id, so a job's tasks all share one schedule. */
    private array $schedules = [];

    private function makeTask(Job $job, array $attributes): JobTask
    {
        $schedule = $this->schedules[$job->id]
            ??= JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);

        return $schedule->tasks()->create(['job_id' => $job->id, ...$attributes]);
    }

    /* ------------------------------------------------------ task assignment */

    public function test_assigning_a_foreman_to_a_new_task_notifies_them(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        $job = $this->makeJob(['status' => 'planning']);

        $this->actingAs($this->planner)->post(route('jobs.tasks.setup.store', $job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $foreman->id]],
        ]);

        $task = JobTask::sole();

        Notification::assertSentTo(
            $foremanUser,
            TaskScheduleChanged::class,
            fn (TaskScheduleChanged $n) => $n->task->is($task) && $n->reason === TaskScheduleChanged::ASSIGNED,
        );
    }

    public function test_assigning_both_a_foreman_and_a_supervisor_notifies_both(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        [$supervisorUser, $supervisor] = $this->makeForeman('Dana', Foreman::ROLE_SUPERVISOR);
        $job = $this->makeJob(['status' => 'planning']);

        $this->actingAs($this->planner)->post(route('jobs.tasks.setup.store', $job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => $foreman->id,
                'supervisor_id' => $supervisor->id,
            ]],
        ]);

        Notification::assertSentTo($foremanUser, TaskScheduleChanged::class);
        Notification::assertSentTo($supervisorUser, TaskScheduleChanged::class);
    }

    public function test_reassigning_a_tasks_foreman_notifies_the_new_one(): void
    {
        [, $foremanA] = $this->makeForeman('Robert');
        [$foremanBUser, $foremanB] = $this->makeForeman('Priya');
        $job = $this->makeJob(['status' => 'planning']);

        $this->actingAs($this->planner)->post(route('jobs.tasks.setup.store', $job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $foremanA->id]],
        ]);
        Notification::fake(); // Clear the assignment notification from creation.

        $task = JobTask::sole();

        $this->actingAs($this->planner)->put(route('tasks.edit.update', $task), [
            'title' => $task->title,
            'status' => $task->status,
            'foreman_id' => $foremanB->id,
            'estimate_item_ids' => [],
        ]);

        Notification::assertSentTo($foremanBUser, TaskScheduleChanged::class);
    }

    public function test_editing_a_task_without_changing_its_assignment_does_not_renotify(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        $job = $this->makeJob(['status' => 'planning']);

        $this->actingAs($this->planner)->post(route('jobs.tasks.setup.store', $job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $foreman->id]],
        ]);
        Notification::fake(); // Clear the assignment notification from creation.

        $task = JobTask::sole();

        $this->actingAs($this->planner)->put(route('tasks.edit.update', $task), [
            'title' => 'Rough-in, revised',
            'status' => $task->status,
            'foreman_id' => $foreman->id,
            'estimate_item_ids' => [],
        ]);

        Notification::assertNotSentTo($foremanUser, TaskScheduleChanged::class);
    }

    /* ---------------------------------------------------------- job started */

    public function test_starting_a_job_notifies_its_supervisor(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        [$supervisorUser, $supervisor] = $this->makeForeman('Dana', Foreman::ROLE_SUPERVISOR);
        $job = $this->makeJob(['foreman_id' => $foreman->id, 'status' => 'scheduled']);
        $this->makeTask($job, [
            'title' => 'Rough-in', 'foreman_id' => $foreman->id,
            'supervisor_id' => $supervisor->id, 'status' => JobTask::STATUS_READY, 'priority' => 'medium',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress'])
            ->assertOk();

        Notification::assertSentTo(
            $supervisorUser,
            JobStarted::class,
            fn (JobStarted $n) => $n->job->is($job),
        );
        // The foreman who started it does not need telling.
        Notification::assertNotSentTo($foremanUser, JobStarted::class);
    }

    public function test_a_job_already_in_progress_does_not_renotify_on_an_unrelated_status_post(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        [$supervisorUser, $supervisor] = $this->makeForeman('Dana', Foreman::ROLE_SUPERVISOR);
        $job = $this->makeJob(['foreman_id' => $foreman->id, 'status' => 'in-progress']);
        $this->makeTask($job, [
            'title' => 'Rough-in', 'foreman_id' => $foreman->id, 'supervisor_id' => $supervisor->id,
            'status' => JobTask::STATUS_READY, 'priority' => 'medium',
        ]);

        // Same status posted again — a no-op transition, not a fresh start.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($foremanUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'in-progress']);

        Notification::assertNotSentTo($supervisorUser, JobStarted::class);
    }

    /* ------------------------------------------------------ job completion */

    public function test_completing_a_job_notifies_foremen_supervisors_and_managers_except_the_actor(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        [$supervisorUser, $supervisor] = $this->makeForeman('Dana', Foreman::ROLE_SUPERVISOR);
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $this->makeTask($job, [
            'title' => 'Rough-in', 'foreman_id' => $foreman->id, 'supervisor_id' => $supervisor->id,
            'status' => JobTask::STATUS_COMPLETED, 'completed_at' => now(), 'priority' => 'medium',
        ]);
        $job->markReadyForReview();
        Notification::fake(); // Clear the ready-for-review notification.

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($supervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        Notification::assertSentTo($foremanUser, JobCompleted::class);
        Notification::assertSentTo($manager, JobCompleted::class);
        // The supervisor who just completed it already knows.
        Notification::assertNotSentTo($supervisorUser, JobCompleted::class);
    }

    public function test_a_second_supervisor_is_notified_of_completion_but_not_the_one_who_completed_it(): void
    {
        [$foremanUser, $foreman] = $this->makeForeman('Robert');
        [$actingSupervisorUser, $actingSupervisor] = $this->makeForeman('Dana', Foreman::ROLE_SUPERVISOR);
        [$otherSupervisorUser, $otherSupervisor] = $this->makeForeman('Sam', Foreman::ROLE_SUPERVISOR);

        $job = $this->makeJob(['foreman_id' => $foreman->id]);
        $this->makeTask($job, [
            'title' => 'Rough-in', 'foreman_id' => $foreman->id, 'supervisor_id' => $actingSupervisor->id,
            'status' => JobTask::STATUS_COMPLETED, 'completed_at' => now(), 'priority' => 'medium',
        ]);
        $this->makeTask($job, [
            'title' => 'Second fix', 'foreman_id' => $foreman->id, 'supervisor_id' => $otherSupervisor->id,
            'status' => JobTask::STATUS_COMPLETED, 'completed_at' => now(), 'priority' => 'medium',
        ]);
        $job->markReadyForReview();
        Notification::fake();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($actingSupervisorUser))
            ->postJson("/api/v1/jobs/{$job->id}/status", ['status' => 'completed'])
            ->assertOk();

        Notification::assertSentTo($otherSupervisorUser, JobCompleted::class);
        Notification::assertNotSentTo($actingSupervisorUser, JobCompleted::class);
    }
}
