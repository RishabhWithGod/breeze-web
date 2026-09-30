<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Client;
use App\Models\CrewShift;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What goes when something is deleted.
 *
 * Client → project → AI result → job → task → schedule: deleting any link takes
 * everything below it, and only what is below it.
 */
class CascadeDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->client = Client::create(['user_id' => $this->user->id, 'name' => 'Acme Co']);
        $this->project = Project::create([
            'user_id' => $this->user->id,
            'client_id' => $this->client->id,
            'name' => 'Office',
            'client' => 'Acme Co',
            'status' => 'draft',
        ]);
    }

    /** A job on the project, with a schedule and two tasks. */
    private function plannedJob(Project $project, ?AiResult $result = null): Job
    {
        $job = Job::create([
            'project_id' => $project->id,
            'name' => 'Job',
            'client' => 'Acme Co',
            'status' => 'scheduled',
            'priority' => 'medium',
            'ai_result_id' => $result?->id,
        ]);

        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);

        foreach (['One', 'Two'] as $position => $title) {
            $job->tasks()->create(['job_schedule_id' => $schedule->id, 'title' => $title, 'position' => $position]);
        }

        CrewShift::create([
            'job_id' => $job->id,
            'crew' => 'Team A',
            'scheduled_date' => '2026-10-05',
            'start_time' => '08:00:00',
            'duration_hours' => 8,
            'status' => CrewShift::STATUS_SCHEDULED,
        ]);

        return $job;
    }

    private function aiResult(Project $project): AiResult
    {
        $aiJob = AiJob::create(['project_id' => $project->id, 'user_id' => $this->user->id, 'status' => 'completed']);

        return AiResult::create([
            'ai_job_id' => $aiJob->id,
            'project_id' => $project->id,
            'original_payload' => [],
        ]);
    }

    private function assertPlanGone(Job $job): void
    {
        $this->assertSame(0, JobTask::where('job_id', $job->id)->count());
        $this->assertSame(0, JobSchedule::where('job_id', $job->id)->count());
        $this->assertSame(0, CrewShift::where('job_id', $job->id)->count());
    }

    public function test_deleting_a_job_deletes_its_tasks_and_schedule(): void
    {
        $job = $this->plannedJob($this->project);
        $other = $this->plannedJob($this->project);

        $job->delete();

        $this->assertPlanGone($job);
        $this->assertSame(2, JobTask::where('job_id', $other->id)->count());
        $this->assertSame(1, JobSchedule::where('job_id', $other->id)->count());
    }

    public function test_deleting_the_last_task_unassigns_the_job_and_deletes_its_schedule(): void
    {
        $job = $this->plannedJob($this->project);

        $job->tasks()->first()->delete();

        // One task left: the plan stays.
        $this->assertSame(1, JobSchedule::where('job_id', $job->id)->count());
        $this->assertSame(0, Job::withoutCrew()->count());

        $job->tasks()->first()->delete();

        $this->assertSame(0, JobSchedule::where('job_id', $job->id)->count());
        $this->assertSame(0, CrewShift::where('job_id', $job->id)->count());
        $this->assertSame([$job->id], Job::withoutCrew()->pluck('id')->all());
    }

    public function test_deleting_an_ai_result_deletes_the_jobs_made_from_it(): void
    {
        $result = $this->aiResult($this->project);
        $fromAi = $this->plannedJob($this->project, $result);
        $byHand = $this->plannedJob($this->project);

        $result->delete();

        $this->assertSoftDeleted($fromAi);
        $this->assertPlanGone($fromAi);
        $this->assertNotSoftDeleted($byHand);
        $this->assertSame(2, JobTask::where('job_id', $byHand->id)->count());
    }

    public function test_deleting_a_project_deletes_its_ai_results_and_jobs(): void
    {
        $result = $this->aiResult($this->project);
        $fromAi = $this->plannedJob($this->project, $result);
        $byHand = $this->plannedJob($this->project);

        $elsewhere = Project::create(['user_id' => $this->user->id, 'name' => 'Other', 'client' => 'Other', 'status' => 'draft']);
        $untouched = $this->plannedJob($elsewhere);

        $this->project->delete();

        $this->assertDatabaseMissing('ai_results', ['id' => $result->id]);
        $this->assertSame(0, AiJob::where('project_id', $this->project->id)->count());
        $this->assertSoftDeleted($fromAi);
        $this->assertSoftDeleted($byHand);
        $this->assertPlanGone($fromAi);
        $this->assertPlanGone($byHand);
        $this->assertNotSoftDeleted($untouched);
        $this->assertSame(2, JobTask::where('job_id', $untouched->id)->count());
    }

    public function test_deleting_a_client_deletes_every_project_and_everything_under_it(): void
    {
        $result = $this->aiResult($this->project);
        $job = $this->plannedJob($this->project, $result);

        $second = Project::create([
            'user_id' => $this->user->id, 'client_id' => $this->client->id, 'name' => 'Second', 'client' => 'Acme Co', 'status' => 'draft',
        ]);
        $secondJob = $this->plannedJob($second);

        $bystander = Client::create(['user_id' => $this->user->id, 'name' => 'Someone Else']);
        $theirs = Project::create([
            'user_id' => $this->user->id, 'client_id' => $bystander->id, 'name' => 'Theirs', 'client' => 'Someone Else', 'status' => 'draft',
        ]);
        $theirJob = $this->plannedJob($theirs);

        $this->actingAs($this->user)
            ->delete(route('clients.destroy', $this->client))
            ->assertSessionHas('warning');

        $this->assertSoftDeleted($this->client);
        $this->assertSoftDeleted($this->project);
        $this->assertSoftDeleted($second);
        $this->assertDatabaseMissing('ai_results', ['id' => $result->id]);
        $this->assertSoftDeleted($job);
        $this->assertSoftDeleted($secondJob);
        $this->assertPlanGone($job);
        $this->assertPlanGone($secondJob);

        $this->assertNotSoftDeleted($bystander);
        $this->assertNotSoftDeleted($theirs);
        $this->assertNotSoftDeleted($theirJob);
        $this->assertSame(2, JobTask::where('job_id', $theirJob->id)->count());
    }
}
