<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileJobScheduleBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_reads_the_schedule_board_and_edits_its_settings(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $job = Job::create([
            'user_id' => $manager->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside', 'client' => 'R', 'status' => 'in-progress',
        ]);
        $h = ['Authorization' => 'Bearer '.$manager->createToken('t')->plainTextToken];

        $board = $this->getJson("/api/v1/office/jobs/{$job->id}/schedule", $h)->assertOk();
        $board->assertJsonStructure(['data' => [
            'job' => ['id', 'name'], 'schedule' => ['workingDays', 'workStartTime', 'timezone', 'status'],
            'tasks', 'progress', 'timeline', 'calendar', 'crewShifts', 'upcoming', 'delays', 'dependencies',
            'resources', 'milestones', 'members', 'options', 'can',
        ]]);

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/office/jobs/{$job->id}/schedule", [
            'working_days' => [1, 2, 3, 4, 5], 'work_start_time' => '07:00', 'work_end_time' => '15:30',
            'break_minutes' => 30, 'timezone' => 'America/Chicago', 'status' => 'draft',
        ], $h)->assertOk();

        $this->app['auth']->forgetGuards();
        $after = $this->getJson("/api/v1/office/jobs/{$job->id}/schedule", $h)->assertOk();
        $this->assertSame('07:00', $after->json('data.schedule.workStartTime'));

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/office/jobs/{$job->id}/schedule", ['working_days' => [], 'work_start_time' => '09:00', 'work_end_time' => '08:00', 'break_minutes' => 0, 'timezone' => 'America/Chicago', 'status' => 'draft'], $h)
            ->assertStatus(422);
    }
}
