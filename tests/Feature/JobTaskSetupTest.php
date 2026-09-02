<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The step straight after Create Job: laying the job out in tasks.
 *
 * The whole list is written in one request, so the rule these tests hold to is
 * all-or-nothing — a half-saved plan is worse than none, because the person who
 * typed it cannot tell which rows landed.
 */
class JobTaskSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Job $job;

    private Foreman $foreman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->foreman = Foreman::create(['name' => 'Casey Reed', 'initials' => 'CR']);

        $client = $this->user->projects()->create([
            'name' => 'Harborview', 'client' => 'Harborview', 'status' => 'draft',
        ]);

        $this->job = Job::create([
            'project_id' => $client->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => '41 Harbor Way',
            'status' => 'planning',
        ]);
    }

    /** A drawing on the client's record — a job is raised against one. */
    private function makeDrawing(Project $client): Upload
    {
        return $client->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
    }

    /**
     * Two priced lines on an estimate belonging to this job.
     *
     * @return array{0: EstimateItem, 1: EstimateItem}
     */
    private function makeEstimateLines(): array
    {
        $estimate = Estimate::create([
            'job_id' => $this->job->id,
            'number' => 'EST-2001',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 900,
            'status' => 'draft',
        ]);

        return [
            EstimateItem::create([
                'estimate_id' => $estimate->id, 'category' => 'labor',
                'description' => 'Pull cable, first floor', 'unit' => 'hr', 'quantity' => 12,
                'unit_cost' => 50, 'total' => 600, 'source' => 'manual', 'position' => 0,
            ]),
            EstimateItem::create([
                'estimate_id' => $estimate->id, 'category' => 'material',
                'description' => 'Receptacles', 'unit' => 'ea', 'quantity' => 30,
                'unit_cost' => 10, 'total' => 300, 'source' => 'manual', 'position' => 1,
            ]),
        ];
    }

    /* ------------------------------------------------- editing one task ---- */

    public function test_the_job_detail_screen_lists_the_work_the_job_is_broken_into(): void
    {
        [$labour] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$labour->id],
            ]],
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.show', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobShow')
                ->has('job.tasks', 1)
                ->where('job.tasks.0.title', 'Rough-in')
                ->where('job.tasks.0.foreman', 'Casey Reed')
                // 12.0 over the wire, which JSON hands back as an int.
                ->where('job.tasks.0.estimatedHours', 12)
                // How much of the estimate it covers, without the lines.
                ->where('job.tasks.0.lineCount', 1)
                ->where('canPlanWork', true));
    }

    public function test_adding_and_editing_from_a_job_returns_to_that_job(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        $task = JobTask::sole();
        $backToJob = route('jobs.show', $this->job);

        // Opened from the job, both screens point home — and say so on the URL
        // they submit to, so the origin survives the save.
        $this->actingAs($this->user)
            ->get(route('tasks.edit', ['task' => $task, 'from' => 'job']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('returnUrl', $backToJob)
                ->where('saveUrl', route('tasks.edit.update', $task).'?from=job'));

        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', ['task' => $task, 'from' => 'job']), [
                'title' => 'Rough-in, first floor',
                'status' => $task->status,
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [],
            ])
            ->assertRedirect($backToJob);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', ['job' => $this->job, 'from' => 'job']), [
                'tasks' => [['title' => 'Second fix', 'foreman_id' => $this->foreman->id]],
            ])
            ->assertRedirect($backToJob);
    }

    public function test_someone_who_cannot_plan_work_still_reads_the_jobs_tasks(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        $electrician = User::factory()->create(['role' => 'Electrician']);

        $this->actingAs($electrician)
            ->get(route('jobs.show', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->has('job.tasks', 1)
                ->where('canPlanWork', false));
    }

    public function test_the_edit_screen_shows_the_task_s_own_lines_as_free_to_pick(): void
    {
        [$labour, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$labour->id],
            ]],
        ]);

        $task = JobTask::sole();

        $this->actingAs($this->user)
            ->get(route('tasks.edit', $task))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskEdit')
                ->where('task.lineIds', [$labour->id])
                // Its own line arrives unclaimed. Left marked, unticking it
                // would grey it out and make putting it back impossible.
                ->where('estimateLines.0.id', $labour->id)
                ->where('estimateLines.0.taskId', null)
                ->where('estimateLines.1.id', $material->id));
    }

    public function test_dropping_a_line_frees_it_and_recomputes_the_hours(): void
    {
        [$labour, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$labour->id, $material->id],
            ]],
        ]);

        $task = JobTask::sole();
        $this->assertSame('12.00', $task->estimated_hours);

        // Hand back the labour line, keep the material one.
        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', $task), [
                'title' => $task->title,
                'status' => $task->status,
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$material->id],
            ])
            ->assertSessionHasNoErrors();

        // Free to plan into another task again.
        $this->assertNull($labour->refresh()->job_task_id);
        $this->assertSame($task->id, $material->refresh()->job_task_id);

        // Hours are re-read off the labour it now covers — none — and the job
        // is recomputed from its tasks rather than adjusted.
        $this->assertNull($task->refresh()->estimated_hours);
        $this->assertNull($this->job->refresh()->estimated_hours);
    }

    public function test_a_line_another_task_holds_cannot_be_taken_by_editing(): void
    {
        [$labour, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [
                ['title' => 'Rough-in', 'foreman_id' => $this->foreman->id, 'estimate_item_ids' => [$labour->id]],
                ['title' => 'Second fix', 'foreman_id' => $this->foreman->id, 'estimate_item_ids' => [$material->id]],
            ],
        ]);

        $roughIn = JobTask::where('title', 'Rough-in')->sole();
        $secondFix = JobTask::where('title', 'Second fix')->sole();

        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', $roughIn), [
                'title' => $roughIn->title,
                'status' => $roughIn->status,
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$labour->id, $material->id],
            ])
            ->assertSessionHasErrors('estimate_item_ids');

        // Nothing moved: the whole save is refused, not partly applied.
        $this->assertSame($secondFix->id, $material->refresh()->job_task_id);
        $this->assertSame($roughIn->id, $labour->refresh()->job_task_id);
    }

    public function test_a_task_on_a_job_with_an_estimate_cannot_be_left_with_no_lines(): void
    {
        [$labour] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [[
                'title' => 'Rough-in',
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [$labour->id],
            ]],
        ]);

        $task = JobTask::sole();

        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', $task), [
                'title' => $task->title,
                'status' => $task->status,
                'foreman_id' => $this->foreman->id,
                'estimate_item_ids' => [],
            ])
            ->assertSessionHasErrors('estimate_item_ids');

        $this->assertSame($task->id, $labour->refresh()->job_task_id);
    }

    public function test_removing_a_task_frees_its_lines_and_recomputes_the_job(): void
    {
        [$labour, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [
                ['title' => 'Rough-in', 'foreman_id' => $this->foreman->id, 'estimate_item_ids' => [$labour->id]],
                ['title' => 'Second fix', 'foreman_id' => $this->foreman->id, 'estimate_item_ids' => [$material->id]],
            ],
        ]);

        $roughIn = JobTask::where('title', 'Rough-in')->sole();
        $this->assertSame('12.00', $this->job->refresh()->estimated_hours);

        $this->actingAs($this->user)
            ->delete(route('tasks.remove', ['task' => $roughIn, 'from' => 'tasks']))
            ->assertRedirect(route('tasks.index'))
            ->assertSessionHas('warning');

        $this->assertSame(1, JobTask::count());
        // Back in the picker for another task to take.
        $this->assertNull($labour->refresh()->job_task_id);
        // The other task's line is untouched.
        $this->assertNotNull($material->refresh()->job_task_id);
        // And the job no longer bills for hours nobody is working.
        $this->assertNull($this->job->refresh()->estimated_hours);
    }

    public function test_removing_a_task_from_a_job_returns_to_that_job(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        $this->actingAs($this->user)
            ->delete(route('tasks.remove', ['task' => JobTask::sole(), 'from' => 'job']))
            ->assertRedirect(route('jobs.show', $this->job));
    }

    public function test_only_someone_who_plans_the_work_may_remove_a_task(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        $electrician = User::factory()->create(['role' => 'Electrician']);

        $this->actingAs($electrician)
            ->delete(route('tasks.remove', JobTask::sole()))
            ->assertForbidden();

        $this->assertSame(1, JobTask::count());
    }

    public function test_a_task_cannot_be_saved_without_a_foreman(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        $task = JobTask::sole();

        // The same rule the setup screen applies: work nobody is running is
        // not planned work.
        $this->actingAs($this->user)
            ->put(route('tasks.edit.update', $task), [
                'title' => $task->title,
                'status' => $task->status,
                'foreman_id' => null,
                'estimate_item_ids' => [],
            ])
            ->assertSessionHasErrors('foreman_id');
    }

    public function test_adding_tasks_from_the_task_list_returns_there(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in', 'foreman_id' => $this->foreman->id]],
        ]);

        // Opened from the list rather than reached while raising the job: the
        // roadmap does not apply, and finishing goes back where you were.
        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', ['job' => $this->job, 'from' => 'tasks']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskSetup')
                ->where('returnUrl', route('tasks.index'))
                ->has('existingTasks', 1));

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', ['job' => $this->job, 'from' => 'tasks']), [
                'tasks' => [['title' => 'Second fix', 'foreman_id' => $this->foreman->id]],
            ])
            ->assertRedirect(route('tasks.index'));

        $this->assertSame(2, JobTask::count());
    }

    public function test_creating_a_job_lands_on_the_task_step(): void
    {
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Fit-out',
                'project_id' => $client->id,
                'address_ids' => [$site->id],
                'upload_id' => $this->makeDrawing($client)->id,
            ])
            ->assertRedirect(route('jobs.tasks.setup', Job::latest('id')->first()));
    }

    public function test_a_draft_is_not_sent_to_be_planned(): void
    {
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        // A draft is not ready for a plan, so it opens on the job itself.
        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Exploratory Retrofit',
                'project_id' => $client->id,
                'address_ids' => [$site->id],
                'upload_id' => $this->makeDrawing($client)->id,
                'save_as_draft' => true,
            ])
            ->assertRedirect(route('jobs.show', Job::latest('id')->first()));
    }

    public function test_the_whole_list_is_saved_in_one_go(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Survey the floor', 'foreman_id' => $dana->id],
                    ['title' => 'Rough-in first floor', 'foreman_id' => $dana->id],
                    ['title' => 'Final inspection', 'foreman_id' => $dana->id],
                ],
            ])
            // The end of the flow: the takeoff is now a job with its work laid out.
            ->assertRedirect(route('jobs.index'))
            ->assertSessionHas('success');

        $tasks = $this->job->refresh()->schedule->tasks;

        $this->assertCount(3, $tasks);
        // Saved in the order they were typed, not by name.
        $this->assertSame(
            ['Survey the floor', 'Rough-in first floor', 'Final inspection'],
            $tasks->pluck('title')->all(),
        );
        $this->assertSame(JobTask::STATUS_PENDING, $tasks[2]->status);
    }

    public function test_a_second_pass_appends_rather_than_renumbering(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor', 'foreman_id' => $dana->id]],
        ]);

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Final inspection', 'foreman_id' => $dana->id]],
        ]);

        $tasks = $this->job->refresh()->schedule->tasks;

        $this->assertSame(['Survey the floor', 'Final inspection'], $tasks->pluck('title')->all());
        $this->assertTrue($tasks[1]->position > $tasks[0]->position);
    }

    public function test_a_duplicate_name_is_reported_on_its_own_row_and_saves_nothing(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor', 'foreman_id' => $this->foreman->id]],
        ]);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Rough-in first floor', 'foreman_id' => $this->foreman->id],
                    // Same name as the one already planned, in a different case.
                    ['title' => '  SURVEY THE FLOOR  ', 'foreman_id' => $this->foreman->id],
                ],
            ])
            ->assertSessionHasErrors('tasks.1.title');

        // All or nothing: the valid first row must not have landed either.
        $this->assertSame(1, $this->job->refresh()->schedule->tasks()->count());
    }

    public function test_two_rows_with_the_same_name_are_caught_before_the_database_is_touched(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Survey the floor', 'foreman_id' => $this->foreman->id],
                    ['title' => 'Survey the floor', 'foreman_id' => $this->foreman->id],
                ],
            ])
            ->assertSessionHasErrors('tasks.1.title');

        $this->assertNull($this->job->refresh()->schedule);
    }

    public function test_the_step_shows_what_is_already_planned(): void
    {
        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Survey the floor', 'foreman_id' => $this->foreman->id]],
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobTaskSetup')
                ->where('job.name', 'Harborview Fit-out')
                // No takeoff behind this job, so no roadmap over it and nowhere
                // for Back to go but the jobs list.
                ->where('job.fromTakeoff', false)
                ->where('job.takeoffUrl', null)
                ->has('existingTasks', 1)
                ->where('existingTasks.0.title', 'Survey the floor')
                ->has('foremen'));
    }

    public function test_a_task_is_built_from_the_jobs_estimate_lines_and_its_foreman(): void
    {
        [$first, $second] = $this->makeEstimateLines();
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in first floor',
                    'estimate_item_ids' => [$first->id, $second->id],
                    'foreman_id' => $dana->id,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $task = $this->job->refresh()->schedule->tasks->sole();

        $this->assertSame([$first->id, $second->id], $task->estimateItems->pluck('id')->all());
        $this->assertSame('Dana Wu', $task->foreman->name);
    }

    public function test_a_planned_line_is_no_longer_offered_to_the_next_task(): void
    {
        [$first, $second] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in first floor', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id]],
        ]);

        // The screen greys it out; the request refuses it, which is what stops a
        // stale tab from quietly moving priced work between two tasks.
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Second fix', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id]],
            ])
            ->assertSessionHasErrors('tasks.0.estimate_item_ids');

        // The free line is still free.
        $this->assertNull($second->refresh()->job_task_id);
    }

    public function test_two_rows_in_one_submission_cannot_claim_the_same_line(): void
    {
        [$first] = $this->makeEstimateLines();

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Rough-in first floor', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id],
                    ['title' => 'Second fix', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id],
                ],
            ])
            ->assertSessionHasErrors('tasks.1.estimate_item_ids');

        $this->assertNull($this->job->refresh()->schedule);
    }

    public function test_a_line_from_another_jobs_estimate_is_refused(): void
    {
        $theirEstimate = Estimate::create([
            'number' => 'EST-9999', 'client' => 'Someone Else', 'project' => 'Someone Else',
            'issued_on' => now()->toDateString(), 'amount' => 100, 'status' => 'draft',
        ]);
        $theirLine = EstimateItem::create([
            'estimate_id' => $theirEstimate->id, 'category' => 'labor',
            'description' => 'Not this job', 'unit' => 'ea', 'quantity' => 1,
            'unit_cost' => 100, 'total' => 100, 'source' => 'manual', 'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Rough-in', 'estimate_item_ids' => [$theirLine->id], 'foreman_id' => $this->foreman->id]],
            ])
            ->assertSessionHasErrors('tasks.0.estimate_item_ids');

        $this->assertNull($theirLine->refresh()->job_task_id);
    }

    public function test_deleting_a_task_frees_its_lines_to_be_planned_again(): void
    {
        [$first] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in first floor', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id]],
        ]);

        $this->job->refresh()->schedule->tasks->sole()->delete();

        // Unplanned again, not lost — the work still has to happen.
        $this->assertNull($first->refresh()->job_task_id);
    }

    public function test_the_step_offers_every_line_with_whatever_already_claimed_it(): void
    {
        [$first, $second] = $this->makeEstimateLines();

        $this->actingAs($this->user)->post(route('jobs.tasks.setup.store', $this->job), [
            'tasks' => [['title' => 'Rough-in first floor', 'estimate_item_ids' => [$first->id], 'foreman_id' => $this->foreman->id]],
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                // Both, not just the free one: a taken row is shown greyed with
                // the task that has it, so the picker can say why.
                ->has('estimateLines', 2)
                ->where('estimateLines.0.taskTitle', 'Rough-in first floor')
                ->where('estimateLines.1.taskId', null)
                ->where('existingTasks.0.lineCount', 1)
                ->has('foremen'));
    }

    public function test_one_foreman_can_run_more_than_one_task(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        // The normal case on a real job, and one a blanket `distinct` rule
        // would have refused.
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [
                    ['title' => 'Rough-in first floor', 'foreman_id' => $dana->id],
                    ['title' => 'Second fix', 'foreman_id' => $dana->id],
                ],
            ])
            ->assertSessionHasNoErrors();

        $tasks = $this->job->refresh()->schedule->tasks;

        $this->assertSame('Dana Wu', $tasks[0]->foreman->name);
        $this->assertSame('Dana Wu', $tasks[1]->foreman->name);
    }

    public function test_every_task_needs_a_foreman(): void
    {
        // Nothing on this step is optional: a task nobody is running is not a
        // plan, it is a note.
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Rough-in first floor']],
            ])
            ->assertSessionHasErrors('tasks.0.foreman_id');

        $this->assertNull($this->job->refresh()->schedule);
    }

    public function test_a_task_needs_the_lines_it_covers_when_the_job_has_an_estimate(): void
    {
        $this->makeEstimateLines();
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Rough-in first floor', 'foreman_id' => $dana->id]],
            ])
            ->assertSessionHasErrors('tasks.0.estimate_item_ids');
    }

    public function test_a_job_with_no_estimate_can_still_be_planned(): void
    {
        $dana = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        /*
         * Nothing to pick, so nothing is demanded — requiring lines here would
         * leave the screen impossible to complete rather than merely strict.
         */
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [['title' => 'Rough-in first floor', 'foreman_id' => $dana->id]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->job->refresh()->schedule->tasks()->count());
    }

    public function test_the_takeoffs_own_estimate_lines_are_offered_even_when_it_named_another_job(): void
    {
        /*
         * The takeoff's estimate is raised when the review is signed off, before
         * any job exists, and is linked to whichever job was raised first. A
         * second job off the same takeoff must still have its work to plan.
         */
        $result = AiResult::create([
            'ai_job_id' => AiJob::create([
                'project_id' => Project::sole()->id,
                'user_id' => $this->user->id,
                'status' => 'completed',
            ])->id,
            'project_id' => Project::sole()->id,
            'original_payload' => [],
        ]);

        $this->job->update(['ai_result_id' => $result->id]);

        $estimate = Estimate::create([
            'job_id' => null,
            'ai_result_id' => $result->id,
            'number' => 'EST-3001',
            'client' => 'Harborview',
            'project' => 'Harborview',
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            'status' => 'draft',
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'category' => 'labor',
            'description' => 'Pull cable', 'unit' => 'hr', 'quantity' => 10,
            'unit_cost' => 50, 'total' => 500, 'source' => 'manual', 'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $this->job))
            ->assertInertia(fn (Assert $page) => $page
                ->has('estimateLines', 1)
                ->where('estimateLines.0.description', 'Pull cable')
                ->where('job.fromTakeoff', true)
                ->where('job.takeoffUrl', route('finals.show', $result->id)));
    }

    public function test_picking_a_client_and_its_drawing_carries_the_estimate_through_to_the_tasks(): void
    {
        /*
         * The whole point of the chain: pick the client and its drawing on
         * Create Job, and the takeoff's estimate comes with it — so the task
         * step opens with the priced work already there to plan.
         */
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $upload = $client->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
        $aiJob = AiJob::create([
            'project_id' => $client->id,
            'upload_id' => $upload->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $result = AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $client->id,
            'upload_id' => $upload->id,
            'original_payload' => [],
        ]);

        // The estimate the review raised, before any job existed.
        $estimate = Estimate::create([
            'ai_result_id' => $result->id,
            'number' => 'EST-4001',
            'client' => $client->name,
            'project' => $client->name,
            'issued_on' => now()->toDateString(),
            'amount' => 500,
            'status' => 'draft',
        ]);
        $result->update(['estimate_id' => $estimate->id]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'category' => 'labor',
            'description' => 'Pull cable', 'unit' => 'hr', 'quantity' => 10,
            'unit_cost' => 50, 'total' => 500, 'source' => 'manual', 'position' => 0,
        ]);

        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Harborview Fit-out',
            'project_id' => $client->id,
            'address_ids' => [$site->id],
            'upload_id' => $upload->id,
        ])->assertSessionHas('success');

        $job = Job::latest('id')->firstOrFail();

        // The drawing's takeoff and its estimate both came with the client.
        $this->assertSame($result->id, $job->ai_result_id);
        $this->assertSame($job->id, $estimate->refresh()->job_id);

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', $job))
            ->assertInertia(fn (Assert $page) => $page
                ->has('estimateLines', 1)
                ->where('estimateLines.0.description', 'Pull cable')
                ->where('estimateLines.0.taskId', null));
    }

    public function test_a_drawing_is_required_to_raise_a_job(): void
    {
        // A job is the work on a drawing. Without one there is no takeoff, no
        // estimate, and nothing for the task step to plan from.
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $this->actingAs($this->user)
            ->post('/jobs', [
                'name' => 'Northgate Fit-out',
                'project_id' => $client->id,
                'address_ids' => [$site->id],
            ])
            ->assertSessionHasErrors('upload_id');
    }

    public function test_a_drawing_that_was_never_priced_leaves_the_task_step_with_nothing_to_plan_from(): void
    {
        // A drawing on record but never taken off has no estimate behind it, so
        // the step has no lines and does not pretend otherwise. Naming the task
        // and its foreman is still enough.
        $client = Project::sole();
        $site = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Northgate Fit-out',
            'project_id' => $client->id,
            'address_ids' => [$site->id],
            'upload_id' => $this->makeDrawing($client)->id,
        ])->assertSessionHas('success');

        $this->actingAs($this->user)
            ->get(route('jobs.tasks.setup', Job::latest('id')->first()))
            ->assertInertia(fn (Assert $page) => $page->has('estimateLines', 0));
    }

    public function test_a_tasks_hours_are_read_off_the_labour_lines_it_covers(): void
    {
        [$labour, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in first floor',
                    'foreman_id' => $this->foreman->id,
                    'estimate_item_ids' => [$labour->id, $material->id],
                ]],
            ])
            ->assertSessionHasNoErrors();

        /*
         * 12 hours of labour. The material line's 30 receptacles are not hours
         * and must not be added to them.
         */
        $this->assertSame('12.00', $this->job->refresh()->schedule->tasks->sole()->estimated_hours);
    }

    public function test_the_jobs_own_hours_are_the_sum_of_its_tasks(): void
    {
        [$labour] = $this->makeEstimateLines();

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Rough-in first floor',
                    'foreman_id' => $this->foreman->id,
                    'estimate_item_ids' => [$labour->id],
                ]],
            ])
            ->assertSessionHasNoErrors();

        // The chain end to end: estimate prices the labour, the task carries it,
        // the job is what its tasks add up to — which is what Scheduling shows.
        $this->assertSame('12.00', $this->job->refresh()->estimated_hours);
    }

    public function test_a_task_covering_no_labour_has_no_hours_rather_than_zero(): void
    {
        [, $material] = $this->makeEstimateLines();

        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), [
                'tasks' => [[
                    'title' => 'Deliver receptacles',
                    'foreman_id' => $this->foreman->id,
                    'estimate_item_ids' => [$material->id],
                ]],
            ])
            ->assertSessionHasNoErrors();

        // Zero would claim the work is free; null says nobody has priced it.
        $this->assertNull($this->job->refresh()->schedule->tasks->sole()->estimated_hours);
    }

    public function test_an_empty_list_is_refused_rather_than_saved_as_a_blank_plan(): void
    {
        $this->actingAs($this->user)
            ->post(route('jobs.tasks.setup.store', $this->job), ['tasks' => []])
            ->assertSessionHasErrors('tasks');
    }
}
