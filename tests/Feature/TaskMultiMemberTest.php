<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TaskScheduleChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A task is given to as many journeymen and foremen as it needs, all equal:
 * the task is one record, so whoever finishes it finishes it for everyone,
 * and each person is on it in their own right.
 */
class TaskMultiMemberTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    private Job $job;

    private Team $north;

    /** @var array<string, array{0: User, 1: Foreman}> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->planner->clients()->create(['name' => 'Harborview']);
        $project = Project::create([
            'user_id' => $this->planner->id, 'client_id' => $client->id,
            'name' => 'Data Hall', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $this->north = Team::create(['name' => 'North Crew']);

        foreach ([
            'priya' => ['Priya Raman', Foreman::ROLE_JOURNEYMAN],
            'jo' => ['Jo Banks', Foreman::ROLE_JOURNEYMAN],
            'torres' => ['Michael Torres', Foreman::ROLE_FOREMAN],
            'dana' => ['Dana Wu', Foreman::ROLE_FOREMAN],
            'robin' => ['Robin Ashby', Foreman::ROLE_APPRENTICE],
        ] as $key => [$name, $role]) {
            $user = User::factory()->create([
                'name' => $name, 'role' => ucfirst($role),
                'registration_source' => User::SOURCE_MOBILE, 'status' => User::STATUS_ACTIVE,
            ]);
            $member = new Foreman(['name' => $name, 'initials' => 'XX', 'role' => $role, 'team_id' => $this->north->id]);
            $member->user_id = $user->id;
            $member->save();
            $this->people[$key] = [$user, $member];
        }

        $this->job = Job::create([
            'user_id' => $this->planner->id, 'client_id' => $client->id, 'project_id' => $project->id,
            'team_id' => $this->north->id, 'name' => 'Riser rewire', 'client' => 'Harborview', 'status' => 'scheduled',
        ]);
    }

    private function id(string $key): int
    {
        return $this->people[$key][1]->id;
    }

    private function user(string $key): User
    {
        return $this->people[$key][0];
    }

    /** Sets a task up through the real screen, with these people on it. */
    private function addTask(array $keys, string $title = 'Rough-in'): JobTask
    {
        $this->actingAs($this->planner)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => $title, 'member_ids' => array_map(fn ($k) => $this->id($k), $keys), 'estimate_item_ids' => []]],
            ])
            ->assertSessionHasNoErrors();

        return JobTask::where('title', $title)->firstOrFail();
    }

    public function test_every_member_picked_is_given_the_task(): void
    {
        $task = $this->addTask(['priya', 'jo', 'torres', 'dana']);

        // The first journeyman and foreman stay on the two columns the rest of the app reads.
        $this->assertSame($this->id('priya'), $task->foreman_id);
        $this->assertSame($this->id('torres'), $task->supervisor_id);

        $this->assertEqualsCanonicalizing([$this->id('priya'), $this->id('jo')], $task->runnerIds()->all());
        $this->assertEqualsCanonicalizing([$this->id('torres'), $this->id('dana')], $task->overseerIds()->all());

        // Every one of them holds it, in their own right.
        foreach (['priya', 'jo', 'torres', 'dana'] as $key) {
            $this->assertTrue(JobTask::heldBy($this->id($key))->whereKey($task->id)->exists(), $key);
        }
        $this->assertFalse(JobTask::heldBy($this->id('robin'))->whereKey($task->id)->exists());
    }

    public function test_the_task_step_offers_one_list_with_each_persons_role(): void
    {
        $this->actingAs($this->planner)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskSetup')
                // Journeymen and foremen — never an apprentice.
                ->has('members', 4)
                ->where('members.0.roleLabel', fn ($label) => in_array($label, ['Journeyman', 'Foreman'], true)));
    }

    public function test_an_apprentice_cannot_be_put_on_a_task(): void
    {
        $this->actingAs($this->planner)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Rough-in', 'member_ids' => [$this->id('priya'), $this->id('torres'), $this->id('robin')], 'estimate_item_ids' => []]],
            ])
            ->assertSessionHasErrors('tasks.0.member_ids');

        $this->assertSame(0, JobTask::count());
    }

    public function test_each_journeyman_on_it_answers_for_it_on_the_job(): void
    {
        $this->addTask(['priya', 'jo', 'torres']);

        // Both journeymen owe their own sign-off; their open work counts it for each.
        $this->assertEqualsCanonicalizing([$this->id('priya'), $this->id('jo')], $this->job->assignedForemanIds()->all());
        $this->assertSame(1, $this->job->myOpenTasksCount($this->id('jo')));
        $this->assertSame(1, $this->job->myOpenTasksCount($this->id('priya')));
    }

    public function test_the_second_journeyman_sees_the_task_in_the_app_and_can_update_it(): void
    {
        $task = $this->addTask(['priya', 'jo', 'torres']);

        // Sanctum keeps the first authenticated user on the guard for the whole test.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->user('jo')->createToken('t')->plainTextToken)
            ->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('data.tasks.0.id', $task->id)
            ->assertJsonPath('data.tasks.0.isMine', true)
            ->assertJsonPath('data.tasks.0.assigneeIsMe', true)
            ->assertJsonCount(3, 'data.tasks.0.assignees');

        // Jo is not the first journeyman, but is on the task in their own right: they can
        // complete it and comment on it. Someone not on it cannot.
        $policy = app(\App\Policies\JobSchedulePolicy::class);
        $this->assertTrue($policy->completeTask($this->user('jo'), $task));
        $this->assertTrue($policy->comment($this->user('jo'), $task));
        $this->assertFalse($policy->completeTask($this->user('dana'), $task->fresh()));
    }

    public function test_finishing_the_task_finishes_it_for_everyone_on_it(): void
    {
        $task = $this->addTask(['priya', 'jo', 'torres']);

        $task->update(['status' => JobTask::STATUS_COMPLETED]);

        // One record: completed for the journeyman who did it and the one who did not.
        $this->assertSame(0, $this->job->myOpenTasksCount($this->id('priya')));
        $this->assertSame(0, $this->job->myOpenTasksCount($this->id('jo')));
    }

    public function test_editing_the_members_replaces_them_and_tells_only_the_new_ones(): void
    {
        Notification::fake();
        $task = $this->addTask(['priya', 'torres']);
        Notification::assertSentTo($this->user('priya'), TaskScheduleChanged::class);
        Notification::assertSentTo($this->user('torres'), TaskScheduleChanged::class);

        Notification::fake();

        $this->actingAs($this->planner)
            ->put(route('tasks.edit.update', $task), [
                'title' => 'Rough-in',
                'status' => $task->status,
                'member_ids' => [$this->id('priya'), $this->id('jo'), $this->id('torres')],
                'estimate_item_ids' => [],
            ])
            ->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertEqualsCanonicalizing([$this->id('priya'), $this->id('jo')], $task->runnerIds()->all());

        // Only Jo is new; the people already on it are not told again.
        Notification::assertSentTo($this->user('jo'), TaskScheduleChanged::class);
        Notification::assertNotSentTo($this->user('priya'), TaskScheduleChanged::class);
        Notification::assertNotSentTo($this->user('torres'), TaskScheduleChanged::class);

        // And taking someone off removes them.
        $this->actingAs($this->planner)
            ->put(route('tasks.edit.update', $task), [
                'title' => 'Rough-in', 'status' => $task->status,
                'member_ids' => [$this->id('jo'), $this->id('torres')], 'estimate_item_ids' => [],
            ])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame([$this->id('jo')], $task->runnerIds()->all());
        $this->assertSame($this->id('jo'), $task->foreman_id);
        $this->assertFalse(JobTask::heldBy($this->id('priya'))->whereKey($task->id)->exists());
    }

    public function test_the_edit_screen_hands_over_everyone_on_the_task(): void
    {
        $task = $this->addTask(['priya', 'jo', 'torres']);

        $this->actingAs($this->planner)
            ->get(route('tasks.edit', $task))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskEdit')
                ->has('task.memberIds', 3)
                ->has('members', 4));
    }

    public function test_someone_on_a_task_cannot_be_removed_from_the_register(): void
    {
        $this->addTask(['priya', 'jo', 'torres']);
        $this->planner->forceFill(['role' => 'Owner'])->save();

        $this->actingAs($this->planner)
            ->delete(route('foremen.destroy', $this->people['jo'][1]))
            ->assertSessionHas('warning');

        $this->assertNotNull(Foreman::find($this->id('jo')));
    }

    public function test_the_mobile_app_swapping_the_first_journeyman_keeps_the_rest(): void
    {
        $task = $this->addTask(['priya', 'jo', 'torres']);

        $old = $task->foreman_id;
        $task->forceFill(['foreman_id' => $this->id('jo')])->save();
        $task->refresh()->replacePrimaries($old, $task->supervisor_id);

        // Priya (the old first journeyman) is replaced by Jo as the first; Jo is not doubled.
        $this->assertSame([$this->id('jo')], $task->runnerIds()->all());
        $this->assertSame([$this->id('torres')], $task->overseerIds()->all());
    }
}
