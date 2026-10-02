<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client or project created without a crew picks up the crew of the first
 * job that has tasks assigned on it.
 */
class JobCrewProjectSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    private Team $north;

    private Foreman $foreman;

    private Foreman $journeyman;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
        $this->north = Team::create(['name' => 'North Crew']);
        $this->foreman = Foreman::create(['name' => 'Dana', 'initials' => 'DW', 'team_id' => $this->north->id, 'role' => 'foreman']);
        $this->journeyman = Foreman::create(['name' => 'Priya', 'initials' => 'PR', 'team_id' => $this->north->id, 'role' => 'journeyman']);
    }

    private function jobWithTask(Project $project, ?Client $client): Job
    {
        $job = Job::create([
            'user_id' => $this->planner->id,
            'team_id' => $this->north->id,
            'project_id' => $project->id,
            'client_id' => $client?->id,
            'name' => 'Rough-in',
            'client' => 'Harborview',
            'status' => 'scheduled',
            'start_date' => now()->toDateString(),
        ]);

        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        $schedule->tasks()->create([
            'job_id' => $job->id,
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
            'status' => 'pending',
            'foreman_id' => $this->journeyman->id,
            'supervisor_id' => $this->foreman->id,
        ]);

        return $job;
    }

    public function test_an_unstaffed_client_and_project_take_the_crew_of_a_job_with_tasks(): void
    {
        $client = Client::create(['user_id' => $this->planner->id, 'name' => 'Harborview']);
        $project = Project::create(['user_id' => $this->planner->id, 'client_id' => $client->id, 'name' => 'Harborview Riser', 'client' => 'Harborview', 'status' => 'draft']);

        $this->jobWithTask($project, $client);

        $this->assertSame($this->north->id, $client->fresh()->team_id);
        $this->assertEqualsCanonicalizing(
            [$this->foreman->id, $this->journeyman->id],
            $project->members()->pluck('foremen.id')->all(),
        );
    }

    public function test_a_crew_chosen_on_purpose_is_left_alone(): void
    {
        $otherTeam = Team::create(['name' => 'South Crew']);
        $chosen = Foreman::create(['name' => 'Sam', 'initials' => 'SM', 'team_id' => $otherTeam->id, 'role' => 'foreman']);
        $client = Client::create(['user_id' => $this->planner->id, 'name' => 'Harborview', 'team_id' => $otherTeam->id]);
        $project = Project::create(['user_id' => $this->planner->id, 'client_id' => $client->id, 'name' => 'Harborview Riser', 'client' => 'Harborview', 'status' => 'draft']);
        $project->members()->sync([$chosen->id]);

        $this->jobWithTask($project, $client);

        $this->assertSame($otherTeam->id, $client->fresh()->team_id);
        $this->assertSame([$chosen->id], $project->members()->pluck('foremen.id')->all());
    }
}
