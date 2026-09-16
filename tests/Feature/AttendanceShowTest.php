<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Attendance detail screen — every field a GPS check-in carries, not
 * just the Time Log Viewer's summary columns. Same crew-visibility rule
 * {@see AttendanceVisibilityTest} already covers for the list itself.
 */
class AttendanceShowTest extends TestCase
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

    public function test_the_owner_sees_the_full_detail(): void
    {
        $job = $this->makeJob();
        $me = User::factory()->create(['role' => 'Electrician', 'name' => 'Jordan Ellis']);

        $attendance = JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $me->id,
            'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => now()->subHours(3),
            'check_in_method' => JobAttendance::METHOD_AUTOMATIC,
            'check_in_lat' => 40.712776,
            'check_in_lng' => -74.005974,
            'check_in_accuracy' => 8.5,
            'check_in_distance_meters' => 12,
            'check_out_at' => now(),
            'check_out_method' => JobAttendance::METHOD_MANUAL,
            'check_out_lat' => 40.7128,
            'check_out_lng' => -74.006,
            'check_out_accuracy' => 6.2,
            'check_out_distance_meters' => 15,
            'banked_seconds' => 1200,
        ]);

        $this->actingAs($me)
            ->get("/time-tracking/attendance/{$attendance->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('AttendanceShow')
                ->where('attendance.id', $attendance->id)
                ->where('attendance.employee.name', 'Jordan Ellis')
                ->where('attendance.job.name', $job->name)
                ->where('attendance.status', 'checkedOut')
                ->where('attendance.bankedSeconds', 1200)
                ->where('attendance.checkIn.method', 'automatic')
                ->where('attendance.checkIn.distanceMeters', fn ($v) => (float) $v === 12.0)
                ->where('attendance.checkOut.method', 'manual'));
    }

    public function test_another_electrician_cannot_view_it(): void
    {
        $job = $this->makeJob();
        $owner = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);

        $attendance = JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => now(),
            'check_in_method' => 'manual',
        ]);

        $this->actingAs($someoneElse)
            ->get("/time-tracking/attendance/{$attendance->id}")
            ->assertForbidden();
    }

    public function test_a_manager_can_view_anyones_check_in(): void
    {
        $job = $this->makeJob();
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $technician = User::factory()->create(['role' => 'Electrician']);

        $attendance = JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $technician->id,
            'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => now(),
            'check_in_method' => 'manual',
        ]);

        $this->actingAs($manager)
            ->get("/time-tracking/attendance/{$attendance->id}")
            ->assertOk();
    }
}
