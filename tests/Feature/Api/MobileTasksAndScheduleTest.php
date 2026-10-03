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

    public function test_each_task_says_whether_it_is_the_users_own(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $job = $this->makeJob(['name' => 'Mine And Crew']);
        $mine = $this->staffOnTask($job, $member);

        // A second task on the same job that belongs to a different crew member.
        $theirs = $job->tasks()->where('id', '!=', $mine->id)->first()
            ?? $job->tasks()->create([
                'job_schedule_id' => $mine->job_schedule_id, 'title' => 'Someone else', 'status' => 'pending', 'position' => 9,
            ]);
        $theirs->assignments()->delete();

        $rows = collect($this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/tasks?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data' => ['tasks' => ['*' => ['isMine', 'startsOn', 'completionPct']]]])
            ->json('data.tasks'))->keyBy('id');

        $this->assertTrue($rows[$mine->id]['isMine']);
        $this->assertFalse($rows[$theirs->id]['isMine']);
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

    public function test_an_assigned_job_with_no_booked_shifts_still_appears_on_the_schedule(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $monday = now()->next('Monday')->startOfDay();
        $job = $this->makeJob([
            'name' => 'Assigned Only',
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->copy()->addDays(4)->toDateString(), // Mon–Fri
        ]);
        \Illuminate\Support\Facades\DB::table('job_assignments')->insert([
            'job_id' => $job->id, 'user_id' => $user->id, 'role' => 'electrician',
            'name' => $user->name, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unassigned = $this->makeJob([
            'name' => 'Not Mine',
            'start_date' => $monday->toDateString(),
            'end_date' => $monday->copy()->addDays(4)->toDateString(),
        ]);

        $shifts = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/schedule?from='.$monday->toDateString().'&to='.$monday->copy()->addDays(6)->toDateString())
            ->assertOk()
            ->json('data.shifts');

        $this->assertCount(5, $shifts, 'five working days Monday to Friday');
        $this->assertSame([$job->id], array_values(array_unique(array_column($shifts, 'jobId'))));
        $this->assertCount(5, array_unique(array_column($shifts, 'id')), 'ids are unique per day');
        $this->assertTrue(collect($shifts)->every(fn ($s) => $s['id'] < 0 && $s['changed'] === false));

        // Real booked shifts take over once the office creates them.
        CrewShift::create(['job_id' => $job->id, 'scheduled_date' => $monday->toDateString()]);
        $this->app['auth']->forgetGuards();
        $after = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/schedule?from='.$monday->toDateString().'&to='.$monday->copy()->addDays(6)->toDateString())
            ->json('data.shifts');
        $this->assertCount(1, $after);
        $this->assertGreaterThan(0, $after[0]['id']);
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

    public function test_the_task_feed_shows_each_role_only_the_crew_it_is_meant_to(): void
    {
        $team = \App\Models\Team::create(['name' => 'Crew A']);
        $other = \App\Models\Team::create(['name' => 'Crew B']);
        $person = function (string $name, string $role, $teamId) {
            $user = User::factory()->create(['name' => $name, 'role' => ucfirst($role), 'registration_source' => User::SOURCE_MOBILE]);
            $row = Foreman::create(['name' => $name, 'initials' => 'XX', 'role' => $role, 'team_id' => $teamId]);
            $row->forceFill(['user_id' => $user->id])->save();

            return [$user, $row];
        };
        [$foremanUser, $foreman] = $person('Fran', 'foreman', $team->id);
        [$journeymanUser, $journeyman] = $person('Joe', 'journeyman', $team->id);
        [, $apprentice] = $person('Amy', 'apprentice', null);
        [, $outsider] = $person('Oz', 'journeyman', $other->id);
        $managerUser = User::factory()->create(['role' => 'Project Manager']);

        \App\Models\JobApprenticeAssignment::create([
            'job_id' => ($job = $this->makeJob())->id, 'journeyman_id' => $journeyman->id, 'apprentice_id' => $apprentice->id,
        ]);

        $schedule = app(ScheduleBuilder::class)->build($job, $managerUser, withTasks: false);
        $task = fn (string $title, Foreman $who) => $schedule->tasks()->create(['job_id' => $job->id, 'title' => $title, 'foreman_id' => $who->id])->id;
        $ids = [
            'foreman' => $task('F', $foreman), 'journeyman' => $task('J', $journeyman),
            'apprentice' => $task('A', $apprentice), 'outsider' => $task('O', $outsider),
        ];

        $seen = function (User $user) use ($ids) {
            // A fresh guard per request, or the first token's user is reused for the next.
            $this->app['auth']->forgetGuards();
            $rows = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
                ->getJson('/api/v1/tasks')->assertOk()->json('data.tasks');

            return collect($rows)->pluck('id')->intersect($ids)->sort()->values()->all();
        };
        $pick = fn (string ...$keys) => collect($keys)->map(fn ($k) => $ids[$k])->sort()->values()->all();

        $this->assertSame($pick('foreman', 'journeyman', 'apprentice', 'outsider'), $seen($managerUser));
        $this->assertSame($pick('foreman', 'journeyman', 'apprentice'), $seen($foremanUser));
        $this->assertSame($pick('journeyman', 'apprentice'), $seen($journeymanUser));
    }

    public function test_a_shift_read_by_date_range_reads_changed_until_it_is_acknowledged(): void
    {
        [$user, $member] = $this->makeMobileJourneyman();
        $job = $this->makeJob(['name' => 'Range Job', 'location' => '1 Main St']);
        $this->staffOnTask($job, $member);

        $day = now()->addDays(2)->toDateString();
        $shift = CrewShift::create(['job_id' => $job->id, 'scheduled_date' => $day, 'start_time' => '08:00:00', 'duration_hours' => 1.5]);
        $outside = CrewShift::create(['job_id' => $job->id, 'scheduled_date' => now()->addDays(20)->toDateString()]);

        $read = function (string $query = '') use ($user) {
            $this->app['auth']->forgetGuards();

            return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))->getJson('/api/v1/schedule'.$query)->assertOk();
        };

        $first = $read("?from={$day}&to={$day}");
        $this->assertSame([$shift->id], collect($first->json('data.shifts'))->pluck('id')->all());
        $first->assertJsonPath('data.shifts.0.endTime', '09:30:00')
            ->assertJsonPath('data.shifts.0.address', '1 Main St')
            ->assertJsonPath('data.shifts.0.changed', false);
        $this->assertNotNull($first->json('data.generatedAt'));

        // The office moves it: it now reads Changed, and the crew is told.
        $this->app['auth']->forgetGuards(); // the office is a different person from the crew member
        $this->actingAs($office ??= User::factory()->create(['role' => 'Project Manager']), 'web');
        $shift->update(['start_time' => '10:00:00']);
        $read("?from={$day}&to={$day}")->assertJsonPath('data.shifts.0.changed', true);
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $user->id)->where('type', 'schedule-changed')->exists());

        $this->travel(5)->seconds(); // timestamps are whole seconds
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/schedule/{$shift->id}/acknowledge")->assertOk()->assertJsonPath('data.changed', false);
        $read("?from={$day}&to={$day}")->assertJsonPath('data.shifts.0.changed', false);
        $this->assertNotContains($outside->id, collect($read("?from={$day}&to={$day}")->json('data.shifts'))->pluck('id')->all());

        // Moved again after being acknowledged: Changed once more.
        $this->travel(5)->seconds();
        $this->app['auth']->forgetGuards();
        $this->actingAs($office ??= User::factory()->create(['role' => 'Project Manager']), 'web');
        $shift->update(['scheduled_date' => now()->addDays(3)->toDateString()]);
        $read('?from='.now()->addDays(3)->toDateString().'&to='.now()->addDays(3)->toDateString())->assertJsonPath('data.shifts.0.changed', true);
    }

    public function test_an_apprentice_can_read_their_schedule(): void
    {
        $apprentice = User::factory()->create(['role' => 'Apprentice', 'registration_source' => User::SOURCE_MOBILE]);
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($apprentice))->getJson('/api/v1/schedule')->assertOk();
    }
}
