<?php

namespace Tests\Feature\Api;

use App\Models\CrewShift;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile cross-job "My Tasks" (`Api\V1\TaskController`) and "My Schedule"
 * (`Api\V1\ScheduleController::index`) — both scoped by the same
 * `ElectricianJobAccess::assignedJobsQuery()` the per-job endpoints use, just
 * flattened across every accessible job instead of one.
 */
class MobileTasksAndScheduleTest extends TestCase
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

    private function makeMobileJourneyman(string $name = 'Chris'): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'Journeyman', 'registration_source' => User::SOURCE_MOBILE]);
        $member = TeamMember::create(['name' => $name, 'initials' => 'CH', 'role' => 'Journeyman', 'user_id' => $user->id]);

        return [$user, $member];
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * `ScheduleBuilder::build(..., withTasks: true)` seeds a full demo
     * sequence of tasks with its own hardcoded placeholder assignments —
     * fine for the app, but it would pollute an isolation test with
     * assignments this test never asked for. `withTasks: false` raises
     * just the schedule envelope, then one task is added by hand here,
     * staffed only with the member under test.
     */
    private function staffOnTask(Job $job, TeamMember $member): JobTask
    {
        $schedule = app(ScheduleBuilder::class)->build($job, User::factory()->create(['role' => 'Project Manager']), withTasks: false);
        $task = $schedule->tasks()->create([
            'job_id' => $job->id,
            'title' => 'Task on '.$job->name,
        ]);
        $task->assignments()->create(['team_member_id' => $member->id, 'role' => 'electrician']);

        return $task;
    }

    // --- Tasks -------------------------------------------------------------

    public function test_tasks_requires_authentication(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_a_restricted_user_only_sees_tasks_on_jobs_they_are_staffed_on(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();

        // Being staffed on one task grants access to the whole job, so
        // every task on that job is expected back (same as the per-job
        // endpoint this one aggregates, which has no per-task assignee
        // filter either) — the point under test is the *other* job's tasks
        // staying fully excluded.
        $staffedJob = $this->makeJob(['name' => 'Staffed Job']);
        $this->staffOnTask($staffedJob, $member);
        $staffedJobTaskIds = $staffedJob->tasks()->pluck('id')->all();

        $otherJob = $this->makeJob(['name' => 'Other Job']);
        $this->staffOnTask($otherJob, TeamMember::create(['name' => 'Someone Else', 'initials' => 'SE', 'role' => 'Electrician']));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/tasks?per_page=100')
            ->assertOk();

        $ids = collect($response->json('data.tasks'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect($staffedJobTaskIds)->sort()->values()->all(), $ids);
    }

    public function test_tasks_response_includes_job_name_and_newest_first(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $job = $this->makeJob(['name' => 'AP NEQ']);
        // Being staffed on one task grants access to the whole job — the
        // aggregate endpoint then returns every task on that job, exactly
        // like the per-job endpoint it mirrors (no per-task assignee filter
        // there either), so the schedule builder's other seeded tasks are
        // expected to show up too.
        $task = $this->staffOnTask($job, $member);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'tasks' => ['*' => ['id', 'jobId', 'jobName', 'title', 'status', 'priority', 'assigneeName', 'assigneeInitials', 'dueDate', 'createdAt']],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);

        $ids = collect($response->json('data.tasks'))->pluck('id');
        $this->assertContains($task->id, $ids->all());
        foreach ($response->json('data.tasks') as $row) {
            $this->assertSame('AP NEQ', $row['jobName']);
        }

        $createdAts = collect($response->json('data.tasks'))->pluck('createdAt');
        $this->assertSame($createdAts->sortDesc()->values()->all(), $createdAts->values()->all());
    }

    public function test_tasks_pagination_is_capped_at_100(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/tasks?per_page=500')
            ->assertOk();

        $this->assertSame(100, $response->json('data.meta.perPage'));
    }

    // --- Schedule ------------------------------------------------------------

    public function test_schedule_requires_authentication(): void
    {
        $this->getJson('/api/v1/schedule')->assertUnauthorized();
    }

    public function test_a_restricted_user_only_sees_shifts_on_jobs_they_are_staffed_on(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();

        $staffedJob = $this->makeJob(['name' => 'Staffed Job']);
        $this->staffOnTask($staffedJob, $member);
        $myShift = CrewShift::create([
            'job_id' => $staffedJob->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $otherJob = $this->makeJob(['name' => 'Other Job']);
        CrewShift::create([
            'job_id' => $otherJob->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/schedule')
            ->assertOk();

        $ids = collect($response->json('data.shifts'))->pluck('id');
        $this->assertSame([$myShift->id], $ids->all());
    }

    public function test_schedule_excludes_past_shifts_and_orders_soonest_first(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $job = $this->makeJob();
        $this->staffOnTask($job, $member);

        $past = CrewShift::create(['job_id' => $job->id, 'scheduled_date' => now()->subDay()->toDateString()]);
        $soon = CrewShift::create(['job_id' => $job->id, 'scheduled_date' => now()->addDay()->toDateString()]);
        $later = CrewShift::create(['job_id' => $job->id, 'scheduled_date' => now()->addWeek()->toDateString()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/schedule')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'shifts' => ['*' => ['id', 'jobId', 'jobName', 'address', 'crewName', 'scheduledDate', 'startTime', 'durationHours', 'status']],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);

        $ids = collect($response->json('data.shifts'))->pluck('id');
        $this->assertSame([$soon->id, $later->id], $ids->all());
        $this->assertNotContains($past->id, $ids->all());
    }
}
