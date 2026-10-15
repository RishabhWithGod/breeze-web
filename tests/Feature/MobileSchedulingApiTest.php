<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Mobile's Scheduling screens read the same board and queue web does — the
 * calendar from `CrewBoard`, the queue as jobs with no tasks yet.
 */
class MobileSchedulingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Foreman $foreman;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-14');

        $this->user = User::factory()->create();
        $this->foreman = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
    }

    public function test_the_calendar_returns_the_crew_board(): void
    {
        $job = $this->makeJob(['name' => 'Panel Upgrade', 'start_date' => '2026-10-13', 'end_date' => '2026-10-15']);
        $this->task($job);

        $this->withToken($this->user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/scheduling/calendar?view=week&date=2026-10-14')
            ->assertOk()
            ->assertJsonPath('data.anchor', '2026-10-14')
            ->assertJsonPath('data.board.view', 'week')
            ->assertJsonCount(7, 'data.board.days')
            ->assertJsonCount(3, 'data.board.blocks')
            ->assertJsonPath('data.board.blocks.0.name', 'Panel Upgrade');
    }

    public function test_the_queue_is_jobs_without_tasks_with_type_counts(): void
    {
        $this->task($this->makeJob(['name' => 'Has Tasks']));
        $this->makeJob(['name' => 'Needs Tasks', 'job_type' => 'residential']);
        $this->makeJob(['name' => 'Also Needs Tasks']);

        $this->withToken($this->user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/scheduling/unassigned?type=residential')
            ->assertOk()
            ->assertJsonCount(1, 'data.jobs')
            ->assertJsonPath('data.jobs.0.name', 'Needs Tasks')
            ->assertJsonPath('data.jobs.0.priority', 'medium')
            ->assertJsonPath('data.counts.all', 2)
            ->assertJsonPath('data.counts.residential', 1)
            ->assertJsonPath('data.counts.commercial', 1);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'user_id' => $this->user->id,
            'project_id' => $this->user->projects()->create([
                'name' => 'Test Project', 'client' => 'Riverside Hospital', 'status' => 'draft',
            ])->id,
            'foreman_id' => $this->foreman->id,
            'name' => 'Test Job',
            'client' => 'Riverside Hospital',
            'location' => '2580 Market Street, Philadelphia',
            'job_type' => 'commercial',
            'status' => 'planning',
            'priority' => 'medium',
            ...$attributes,
        ]);
    }

    private function task(Job $job): void
    {
        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);

        $job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
            'foreman_id' => $this->foreman->id,
        ]);
    }
}
