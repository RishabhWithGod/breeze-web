<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Where Back goes from a job.
 *
 * A job is opened from the jobs list, from the scheduling screens and from the
 * task list. Back has to undo the step that was actually taken — sending
 * someone who came from Scheduling to the jobs list is not going back, it is
 * going somewhere else, and they lose the queue they were working through.
 */
class JobBackNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Job $job;

    private Client $client;

    private ClientAddress $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->client = $this->user->clients()->create(['name' => 'Harborview']);
        $project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'name' => 'Data Hall',
            'client' => 'Harborview',
            'status' => 'draft',
        ]);

        $this->site = $this->client->addresses()->create([
            'label' => 'Tower',
            'address' => '1 Harbor Way',
            'is_primary' => true,
            'position' => 0,
        ]);

        $this->job = Job::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'project_id' => $project->id,
            'name' => 'Riser rewire',
            'client' => 'Harborview',
            'status' => 'scheduled',
        ]);
        $this->job->addresses()->sync([$this->site->id => ['position' => 0]]);
    }

    public static function origins(): array
    {
        return [
            'unassigned queue' => ['scheduling', '/scheduling'],
            'calendar' => ['scheduling-calendar', '/scheduling/calendar'],
            'availability' => ['scheduling-availability', '/scheduling/availability'],
            'task list' => ['tasks', '/tasks'],
        ];
    }

    /** @dataProvider origins */
    public function test_back_returns_to_the_screen_the_job_was_opened_from(string $from, string $path): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.show', [$this->job, 'from' => $from]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('back.url', url($path))
            );
    }

    public function test_a_job_opened_from_nowhere_goes_back_to_the_jobs_list(): void
    {
        // A refresh, a bookmark, or a link that said nothing: the module's own
        // list is the honest answer.
        $this->actingAs($this->user)
            ->get(route('jobs.show', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->where('back.url', url('/jobs'))
                ->where('back.label', 'Back to jobs')
            );
    }

    public static function hostileOrigins(): array
    {
        return [
            'another site' => ['https://evil.example.com'],
            'a path' => ['../../etc/passwd'],
            'a name nobody serves' => ['invoices'],
        ];
    }

    /**
     * The origin is a name matched against a list, never a URL.
     *
     * A URL in the query string would let any link anywhere decide where a
     * button on this page points — including off this site entirely.
     *
     * @dataProvider hostileOrigins
     */
    public function test_an_origin_that_is_not_on_the_list_falls_back(string $from): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.show', [$this->job, 'from' => $from]))
            ->assertInertia(fn (Assert $page) => $page->where('back.url', url('/jobs')));
    }

    /**
     * The trail survives an edit.
     *
     * Opening a job from Scheduling, correcting it and saving used to land on a
     * job that had forgotten where it came from — the same lost step, one screen
     * deeper. Back, Cancel and the save itself all carry it.
     */
    public function test_saving_an_edit_returns_to_a_job_that_still_knows_the_way_back(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.edit', [$this->job, 'from' => 'scheduling']))
            ->assertInertia(fn (Assert $page) => $page->where('from', 'scheduling'));

        $this->actingAs($this->user)
            ->put(route('jobs.update', [$this->job, 'from' => 'scheduling']), [
                'name' => 'Riser rewire',
                'client_id' => $this->client->id,
                'project_id' => $this->job->project_id,
                'address_ids' => [$this->site->id],
                'status' => 'scheduled',
                // Both dates, as UpdateJobRequest requires them.
                'start_date' => '2026-03-02',
                'end_date' => '2026-03-20',
            ])
            ->assertRedirect(route('jobs.show', [$this->job, 'from' => 'scheduling']));
    }

    public function test_an_edit_does_not_carry_an_origin_nobody_serves(): void
    {
        $this->actingAs($this->user)
            ->get(route('jobs.edit', [$this->job, 'from' => 'https://evil.example.com']))
            ->assertInertia(fn (Assert $page) => $page->where('from', null));
    }

    /**
     * The path is walked in reverse, one step at a time.
     *
     * Calendar → job → task, then Back, Back. The task returns to the job, and
     * the job returns to the calendar. Before this the job had forgotten its own
     * trail by the time the task handed it back, and the second Back dropped the
     * planner in the jobs list — two steps sideways instead of two steps out.
     */
    public function test_a_task_hands_the_job_back_with_its_trail_intact(): void
    {
        $schedule = JobSchedule::create([
            'job_id' => $this->job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);
        $task = $this->job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
        ]);

        // Out of the task: back to the job, still carrying the calendar.
        $this->actingAs($this->user)
            ->get(route('tasks.edit', [$task, 'from' => 'job', 'origin' => 'scheduling-calendar']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('returnUrl', route('jobs.show', [$this->job, 'from' => 'scheduling-calendar'])));

        // And out of the job: back to the calendar itself.
        $this->actingAs($this->user)
            ->get(route('jobs.show', [$this->job, 'from' => 'scheduling-calendar']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('back.url', url('/scheduling/calendar')));
    }

    /** A trail nobody serves is dropped on the way out of a task too. */
    public function test_a_task_does_not_hand_back_an_origin_nobody_serves(): void
    {
        $schedule = JobSchedule::create([
            'job_id' => $this->job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);
        $task = $this->job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->get(route('tasks.edit', [$task, 'from' => 'job', 'origin' => 'https://evil.example.com']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('returnUrl', route('jobs.show', $this->job)));
    }
}
