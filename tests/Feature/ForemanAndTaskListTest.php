<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\User;
use App\Services\Scheduling\ScheduleBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The two lists that sit under Jobs: every task across every job, and the
 * foremen those tasks can be handed to.
 */
class ForemanAndTaskListTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    private User $electrician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
        $this->electrician = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_the_foreman_list_counts_the_open_work_each_is_carrying(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        Foreman::create(['name' => 'Luis Ortega', 'initials' => 'LO']);

        $this->taskFor($dana, 'Rough-in first floor');
        $this->taskFor($dana, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');

        // Finished work does not make anyone busy, so it is counted apart.
        $this->taskFor($dana, 'Second fix')
            ->update(['status' => JobTask::STATUS_COMPLETED]);

        /*
         * Neither has been put on a crew, so both are under "Not on a team" —
         * a real state the register shows rather than hides.
         */
        $this->actingAs($this->planner)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                ->has('unassigned', 2)
                // The screen reads `teams.meta.current_page` for its pager.
                ->where('teams.meta.current_page', 1)
                ->has('teams.meta.last_page')
                // Ordered by name, and the counts are the point of the list.
                ->where('unassigned.0.name', 'Dana Wu')
                ->where('unassigned.0.openTasks', 2)
                // Two open tasks, but across two different jobs.
                ->where('unassigned.0.openJobs', 2)
                ->where('unassigned.1.name', 'Luis Ortega')
                ->where('unassigned.1.openTasks', 0)
                ->where('unassigned.1.openJobs', 0)
                ->where('canManage', true));
    }

    public function test_the_detail_screen_shows_everything_on_record_and_the_open_work(): void
    {
        $dana = Foreman::create([
            'name' => 'Dana Wu',
            'initials' => 'DW',
            'phone' => '(415) 555-0134',
            'email' => 'dana@example.com',
            'licence_number' => 'EC-4471',
            'started_on' => '2024-03-04',
            'notes' => 'Runs service work.',
        ]);

        $this->taskFor($dana, 'Rough-in first floor');
        $this->taskFor($dana, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');
        $this->taskFor($dana, 'Second fix')->update(['status' => JobTask::STATUS_COMPLETED]);

        $this->actingAs($this->planner)
            ->get(route('foremen.show', $dana))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForemanShow')
                ->where('foreman.name', 'Dana Wu')
                ->where('foreman.phone', '(415) 555-0134')
                ->where('foreman.email', 'dana@example.com')
                ->where('foreman.licenceNumber', 'EC-4471')
                ->where('foreman.joinedOn', '2024-03-04')
                ->where('foreman.notes', 'Runs service work.')
                ->where('foreman.openTasks', 2)
                ->where('foreman.openJobs', 2)
                ->where('foreman.completedTasks', 1)
                // Open work only, and it names the job each task is on.
                ->has('tasks', 2)
                ->where('tasks.0.jobName', 'Eastside Depot')
                ->where('tasks.0.client', 'Eastside Holdings')
                ->where('canManage', true));
    }

    public function test_the_detail_screen_leaves_out_work_on_a_deleted_job(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor($dana, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');

        Job::where('name', 'Eastside Depot')->sole()->delete();

        $this->actingAs($this->planner)
            ->get(route('foremen.show', $dana))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 0)
                ->where('foreman.openTasks', 0));
    }

    public function test_a_foremans_details_can_be_corrected(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW', 'phone' => 'old']);

        $this->actingAs($this->planner)
            ->put(route('foremen.update', $dana), [
                'name' => 'Dana Okonkwo',
                'role' => 'foreman',
                'phone' => '(415) 555-0134',
                'email' => 'dana@example.com',
                'licence_number' => 'EC-4471',
                'started_on' => '2024-03-04',
                'notes' => 'Runs service work.',
            ])
            ->assertRedirect(route('foremen.show', $dana))
            ->assertSessionHas('success');

        $dana->refresh();

        $this->assertSame('Dana Okonkwo', $dana->name);
        // Re-derived, so a rename cannot leave the old name's initials behind.
        $this->assertSame('DO', $dana->initials);
        $this->assertSame('(415) 555-0134', $dana->phone);
        $this->assertSame('Runs service work.', $dana->notes);
    }

    public function test_keeping_a_foremans_own_name_is_not_a_clash(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->put(route('foremen.update', $dana), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => '4155550134'])
            ->assertSessionHasNoErrors();

        // Stored the one way the app writes a number, whatever was typed.
        $this->assertSame('(415) 555-0134', $dana->refresh()->phone);
    }

    public function test_a_name_another_foreman_already_has_is_refused_on_edit(): void
    {
        Foreman::create(['name' => 'Luis Ortega', 'initials' => 'LO']);
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->put(route('foremen.update', $dana), ['name' => 'Luis Ortega', 'role' => 'foreman'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Dana Wu', $dana->refresh()->name);
    }

    public function test_a_foreman_who_has_never_been_handed_work_can_be_removed(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->delete(route('foremen.destroy', $dana))
            ->assertRedirect(route('teams.index'))
            ->assertSessionHas('warning');

        $this->assertSame(0, Foreman::count());
    }

    public function test_a_foreman_who_has_run_work_is_kept(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $task = $this->taskFor($dana);
        $task->update(['status' => JobTask::STATUS_COMPLETED]);

        // `job_tasks.foreman_id` is SET NULL, so the delete would look tidy and
        // quietly erase who ran the work.
        $this->actingAs($this->planner)
            ->delete(route('foremen.destroy', $dana))
            ->assertSessionHas('warning');

        $this->assertSame(1, Foreman::count());
        $this->assertSame($dana->id, $task->refresh()->foreman_id);
    }

    public function test_only_someone_who_can_staff_work_may_edit_or_remove_a_foreman(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->electrician)
            ->get(route('foremen.edit', $dana))
            ->assertForbidden();

        $this->actingAs($this->electrician)
            ->put(route('foremen.update', $dana), ['name' => 'Renamed', 'role' => 'foreman'])
            ->assertForbidden();

        $this->actingAs($this->electrician)
            ->delete(route('foremen.destroy', $dana))
            ->assertForbidden();

        $this->assertSame('Dana Wu', $dana->refresh()->name);
    }

    public function test_anyone_signed_in_can_read_a_foremans_record(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        // Reading who runs work is not a planning decision — acting on it is.
        $this->actingAs($this->electrician)
            ->get(route('foremen.show', $dana))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForemanShow')
                ->where('canManage', false));
    }

    public function test_work_on_a_deleted_job_is_not_counted_against_a_foreman(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor($dana, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');

        Job::where('name', 'Eastside Depot')->sole()->delete();

        // Nobody is carrying work on a job that no longer exists.
        $this->actingAs($this->planner)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->where('unassigned.0.openTasks', 0));
    }

    public function test_a_foreman_is_added_with_the_details_recorded_alongside_the_name(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'foreman',
                'phone' => '  (415) 555-0134  ',
                'email' => 'dana@example.com',
                'licence_number' => 'EC-4471',
                'started_on' => '2024-03-04',
                'notes' => 'Runs service work.',
            ])
            ->assertRedirect(route('teams.index'));

        $foreman = Foreman::sole();

        // Never asked for on the form — the name is what gives them.
        $this->assertSame('DW', $foreman->initials);
        $this->assertSame('(415) 555-0134', $foreman->phone);
        $this->assertSame('dana@example.com', $foreman->email);
        $this->assertSame('EC-4471', $foreman->licence_number);
        $this->assertSame('2024-03-04', $foreman->started_on->toDateString());
        $this->assertSame('Runs service work.', $foreman->notes);
    }

    public function test_an_untyped_detail_is_stored_as_nothing_rather_than_an_empty_string(): void
    {
        // Only the name is required, so "has a phone number" has to be one
        // check everywhere rather than two.
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'foreman', 'phone' => '  '])
            ->assertSessionHasNoErrors();

        $foreman = Foreman::sole();

        $this->assertNull($foreman->phone);
        $this->assertNull($foreman->email);
        $this->assertNull($foreman->started_on);
    }

    public function test_an_address_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'foreman', 'email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Foreman::count());
    }

    public function test_the_list_shows_how_to_reach_each_foreman(): void
    {
        Foreman::create([
            'name' => 'Dana Wu',
            'initials' => 'DW',
            'phone' => '(415) 555-0134',
            'email' => 'dana@example.com',
            'licence_number' => 'EC-4471',
            'started_on' => '2024-03-04',
        ]);

        $this->actingAs($this->planner)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('unassigned.0.phone', '(415) 555-0134')
                ->where('unassigned.0.email', 'dana@example.com')
                ->where('unassigned.0.licenceNumber', 'EC-4471')
                ->where('unassigned.0.joinedOn', '2024-03-04'));
    }

    public function test_initials_are_always_taken_from_the_name(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'dana wu',
                'role' => 'foreman',
                // The form has no initials field, and a request that sends one
                // anyway does not get to override what the name says.
                'initials' => 'ZZ',
            ])
            ->assertRedirect(route('teams.index'))
            ->assertSessionHas('success');

        $this->assertSame('DW', Foreman::sole()->initials);
    }

    public function test_a_name_already_on_the_list_is_refused(): void
    {
        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Foreman::count());
    }

    public function test_only_someone_who_can_staff_work_may_add_a_foreman(): void
    {
        // A foreman exists to be handed tasks, so adding one is the same
        // decision as staffing them.
        $this->actingAs($this->electrician)
            ->get(route('foremen.create'))
            ->assertForbidden();

        $this->actingAs($this->electrician)
            ->post(route('foremen.store'), ['name' => 'Dana Wu'])
            ->assertForbidden();

        $this->assertSame(0, Foreman::count());
    }

    public function test_anyone_signed_in_can_read_the_foreman_list(): void
    {
        $this->actingAs($this->electrician)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    }

    public function test_the_task_list_spans_every_job_and_names_the_job_each_belongs_to(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $task = $this->taskFor($dana);

        $this->actingAs($this->planner)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tasks')
                ->has('jobs.data', 1)
                // The screen reads `jobs.meta.current_page` to draw its pager.
                // A bare paginator serialises flat and has no `meta` at all,
                // which rendered the list as a blank screen.
                ->where('jobs.meta.current_page', 1)
                ->has('jobs.meta.last_page')
                ->where('jobs.data.0.name', 'Harborview Fit-out')
                ->where('jobs.data.0.client', 'Harborview')
                ->has('jobs.data.0.tasks', 1)
                ->where('jobs.data.0.tasks.0.title', $task->title)
                ->where('jobs.data.0.tasks.0.foreman', 'Dana Wu'));
    }

    public function test_the_task_list_can_be_narrowed_to_the_unassigned(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor($dana, 'Rough-in first floor');
        $this->taskFor(null, 'Second fix');

        $this->actingAs($this->planner)
            ->get(route('tasks.index', ['foreman' => 'unassigned']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->has('jobs.data.0.tasks', 1)
                ->where('jobs.data.0.tasks.0.title', 'Second fix'));
    }

    public function test_adding_a_task_starts_by_offering_the_open_jobs(): void
    {
        $this->taskFor(null);

        // A task cannot exist on its own, so this picks the job first.
        $this->actingAs($this->planner)
            ->get(route('tasks.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TaskCreate')
                ->has('jobs', 1)
                ->where('jobs.0.name', 'Harborview Fit-out'));
    }

    public function test_the_list_groups_every_job_s_tasks_under_the_job_and_names_its_client(): void
    {
        // Created interleaved on purpose: the screen pages by job, so each job
        // carries its own tasks however they were entered.
        $this->taskFor(null, 'Harborview rough-in');
        $this->taskFor(null, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');
        $this->taskFor(null, 'Harborview second fix');
        $this->taskFor(null, 'Depot second fix', 'Eastside Depot', 'Eastside Holdings');

        $this->actingAs($this->planner)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 2)
                // Newest job first, with its two tasks under it.
                ->where('jobs.data.0.name', 'Eastside Depot')
                ->where('jobs.data.0.client', 'Eastside Holdings')
                ->has('jobs.data.0.tasks', 2)
                ->where('jobs.data.1.name', 'Harborview Fit-out')
                ->where('jobs.data.1.client', 'Harborview')
                ->has('jobs.data.1.tasks', 2));
    }

    public function test_a_job_whose_tasks_were_all_removed_is_still_listed_to_add_to(): void
    {
        $this->taskFor(null, 'Harborview rough-in');
        $empty = Job::create([
            'name' => 'Eastside Depot',
            'client' => 'Eastside Holdings',
            'location' => '9 Depot Road',
            'status' => 'planning',
        ]);

        // Nothing to group under it, and it still has to appear — otherwise
        // removing a job's last task takes away the way to add one back.
        $this->actingAs($this->planner)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 2)
                ->where('jobs.data.0.id', $empty->id)
                ->has('jobs.data.0.tasks', 0));
    }

    public function test_a_job_with_nothing_matching_the_filters_drops_out(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor($dana, 'Harborview rough-in');
        $this->taskFor(null, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');

        // Narrowed, an empty job is noise rather than a way in.
        $this->actingAs($this->planner)
            ->get(route('tasks.index', ['foreman' => 'Dana Wu']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Harborview Fit-out')
                ->has('jobs.data.0.tasks', 1));
    }

    public function test_the_list_offers_the_foremen_a_task_can_be_handed_to(): void
    {
        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor(null);

        $this->actingAs($this->planner)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('foremen', 1)
                ->where('foremen.0.name', 'Dana Wu')
                ->where('canEdit', true));
    }

    public function test_a_deleted_jobs_tasks_are_not_listed_as_outstanding_work(): void
    {
        $this->taskFor(null, 'Harborview rough-in');
        $this->taskFor(null, 'Depot rough-in', 'Eastside Depot', 'Eastside Holdings');

        // A soft delete with an Undo behind it, so the tasks are kept — but
        // until the job is restored there is nothing to group them under, and
        // every link on the row would point at a job that will not resolve.
        Job::where('name', 'Eastside Depot')->sole()->delete();

        $this->actingAs($this->planner)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Harborview Fit-out'));

        $this->assertSame(2, JobTask::count());
    }

    public function test_editing_a_task_whose_job_is_gone_is_a_missing_page_not_an_error(): void
    {
        $task = $this->taskFor(null);
        Job::where('name', 'Harborview Fit-out')->sole()->delete();

        $this->actingAs($this->planner)
            ->get(route('tasks.edit', $task))
            ->assertNotFound();
    }

    public function test_editing_a_task_opens_the_screen_that_created_it(): void
    {
        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $task = $this->taskFor(null);

        $this->actingAs($this->planner)
            ->get(route('tasks.edit', ['task' => $task, 'from' => 'tasks']))
            ->assertInertia(fn (Assert $page) => $page
                // The same screen as the setup step, aimed at one row.
                ->component('JobTaskEdit')
                ->where('task.id', $task->id)
                ->where('task.title', $task->title)
                ->where('job.name', 'Harborview Fit-out')
                ->where('returnUrl', route('tasks.index'))
                ->has('foremen', 1)
                ->has('statuses'));
    }

    public function test_a_task_can_be_retitled_and_handed_to_someone_else(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $task = $this->taskFor(null);

        $this->actingAs($this->planner)
            ->put(route('tasks.edit.update', ['task' => $task, 'from' => 'tasks']), [
                'title' => '  Rough-in second floor  ',
                'status' => JobTask::STATUS_IN_PROGRESS,
                'foreman_id' => $dana->id,
                'estimate_item_ids' => [],
            ])
            ->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success');

        $task->refresh();

        $this->assertSame('Rough-in second floor', $task->title);
        $this->assertSame(JobTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertSame($dana->id, $task->foreman_id);
    }

    public function test_a_title_another_task_on_the_job_already_has_is_refused(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->taskFor($dana, 'Second fix');
        $task = $this->taskFor($dana, 'Rough-in first floor');

        $this->actingAs($this->planner)
            ->put(route('tasks.edit.update', $task), [
                'title' => 'second fix',
                'status' => $task->status,
                'foreman_id' => $dana->id,
                'estimate_item_ids' => [],
            ])
            ->assertSessionHasErrors('title');

        $this->assertSame('Rough-in first floor', $task->refresh()->title);
    }

    public function test_only_someone_who_plans_the_work_may_edit_a_task(): void
    {
        $task = $this->taskFor(null);

        $this->actingAs($this->electrician)
            ->get(route('tasks.edit', $task))
            ->assertForbidden();

        // The list itself still reads, it just does not offer the button.
        $this->actingAs($this->electrician)
            ->get(route('tasks.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false));
    }

    private function taskFor(
        ?Foreman $foreman,
        string $title = 'Rough-in first floor',
        string $jobName = 'Harborview Fit-out',
        string $client = 'Harborview',
    ): JobTask {
        $job = Job::firstOrCreate(
            ['name' => $jobName],
            ['client' => $client, 'location' => '41 Harbor Way', 'status' => 'planning'],
        );

        // Built the way the app builds one — a schedule has working days, a
        // window and a calendar, none of which a bare insert supplies.
        $schedule = $job->schedule
            ?? app(ScheduleBuilder::class)->build($job, $this->planner, withTasks: false);

        return $schedule->tasks()->create([
            'job_id' => $job->id,
            'foreman_id' => $foreman?->id,
            'title' => $title,
            'status' => JobTask::STATUS_PENDING,
            'position' => $schedule->tasks()->count(),
        ]);
    }
}
