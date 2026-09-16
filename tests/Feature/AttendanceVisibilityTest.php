<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who sees whose GPS check-ins on the web Time Tracking page — the same
 * crew-visibility rule `TimeEntry` already uses: an electrician sees only
 * their own, a foreman/supervisor/manager sees everyone's. Each check-in
 * with no timer/manual entry on the same day shows as its own
 * attendance-only day row (`DailyTimesheetBuilder`).
 */
class AttendanceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'status' => 'in-progress',
        ]);
    }

    private function checkedInRow(Job $job, User $user): JobAttendance
    {
        return JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => now(),
            'check_in_method' => 'manual',
        ]);
    }

    public function test_an_electrician_sees_only_their_own_check_in(): void
    {
        $job = $this->makeJob();
        $me = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);
        $this->checkedInRow($job, $me);
        $this->checkedInRow($job, $someoneElse);

        $this->actingAs($me)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.employee.name', $me->name)
                ->where('days.data.0.hasAttendance', true));
    }

    public function test_a_manager_sees_every_technicians_check_in(): void
    {
        $job = $this->makeJob();
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $foreman = User::factory()->create(['role' => 'Electrician']);
        $supervisor = User::factory()->create(['role' => 'Electrician']);
        $this->checkedInRow($job, $foreman);
        $this->checkedInRow($job, $supervisor);

        $this->actingAs($manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page->has('days.data', 2));
    }
}
