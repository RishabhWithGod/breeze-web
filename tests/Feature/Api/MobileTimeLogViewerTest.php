<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile's Time Log Viewer — full parity with web's own
 * `TimeEntryController::index()`/`showDay()`/`showAttendance()`: one row
 * per technician per day (`DailyTimesheetBuilder`), merging GPS
 * `JobAttendance` sessions alongside timer/manual `TimeEntry` ones.
 */
class MobileTimeLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // --- Days (day-grouped list) --------------------------------------------

    public function test_days_groups_multiple_entries_on_the_same_day_into_one_row(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $today = now()->toDateString();

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => $today,
            'hours' => 3, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);
        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => $today,
            'hours' => 2, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-tracking/days')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'days' => ['*' => [
                        'userId', 'date', 'employee' => ['name', 'role'], 'jobs', 'totalHours',
                        'sessionCount', 'status', 'hasTimerEntries', 'hasAttendance', 'attendanceStatus',
                    ]],
                    'meta' => ['currentPage', 'lastPage', 'total'],
                    'can',
                ],
            ]);

        $this->assertCount(1, $response->json('data.days'));
        $this->assertSame(2, $response->json('data.days.0.sessionCount'));
        $this->assertEqualsWithDelta(5.0, $response->json('data.days.0.totalHours'), 0.001);
        $this->assertTrue($response->json('data.days.0.hasTimerEntries'));
        $this->assertFalse($response->json('data.days.0.hasAttendance'));
    }

    public function test_a_day_with_only_gps_attendance_has_no_approval_status(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        JobAttendance::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => now()->subHours(4), 'check_out_at' => now(),
            'banked_seconds' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-tracking/days')
            ->assertOk();

        $this->assertCount(1, $response->json('data.days'));
        $this->assertNull($response->json('data.days.0.status'));
        $this->assertSame('checkedOut', $response->json('data.days.0.attendanceStatus'));
        $this->assertTrue($response->json('data.days.0.hasAttendance'));
    }

    public function test_days_only_shows_the_signed_in_users_own_rows_by_default(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'hours' => 3, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);
        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $someoneElse->id, 'date' => now()->toDateString(),
            'hours' => 5, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-tracking/days')
            ->assertOk();

        $this->assertCount(1, $response->json('data.days'));
    }

    public function test_a_manager_with_view_crew_sees_every_technicians_days(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $electrician = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $electrician->id, 'date' => now()->toDateString(),
            'hours' => 3, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/time-tracking/days')
            ->assertOk();

        $this->assertCount(1, $response->json('data.days'));
    }

    // --- Day detail -------------------------------------------------------

    public function test_day_detail_returns_entries_and_attendance_for_that_day(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $today = now()->toDateString();

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => $today,
            'hours' => 3, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);
        JobAttendance::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => $today,
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => now()->subHours(2), 'check_out_at' => now(),
            'banked_seconds' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/time-tracking/days/{$user->id}/{$today}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'userId', 'date', 'employee', 'totalHours', 'status', 'attendanceStatus',
                    'jobBreakdown', 'entries', 'attendance' => ['*' => ['id', 'hours', 'hasPhoto']],
                ],
            ]);

        $this->assertSame(1, count($response->json('data.entries')));
        $this->assertSame(1, count($response->json('data.attendance')));
    }

    public function test_day_detail_404s_when_nothing_was_recorded(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/time-tracking/days/{$user->id}/".now()->toDateString())
            ->assertStatus(404);
    }

    public function test_a_technician_cannot_view_someone_elses_day(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $intruder = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $today = now()->toDateString();

        TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => $today,
            'hours' => 3, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->getJson("/api/v1/time-tracking/days/{$user->id}/{$today}")
            ->assertStatus(403);
    }

    // --- Attendance detail --------------------------------------------------

    public function test_a_technician_can_view_their_own_attendance_detail(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $attendance = JobAttendance::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_IN,
            'check_in_at' => now()->subHours(1), 'check_in_lat' => 40.0, 'check_in_lng' => -74.0,
            'check_in_accuracy' => 5.0, 'check_in_method' => 'automatic',
            'banked_seconds' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/attendance/{$attendance->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'date', 'status', 'employee', 'job', 'hours', 'bankedSeconds', 'checkIn', 'checkOut'],
            ])
            ->assertJsonPath('data.status', 'checkedIn')
            ->assertJsonPath('data.checkIn.method', 'automatic');
    }

    public function test_a_technician_cannot_view_someone_elses_attendance(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $intruder = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $attendance = JobAttendance::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => now()->subHours(2), 'check_out_at' => now(),
            'banked_seconds' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->getJson("/api/v1/attendance/{$attendance->id}")
            ->assertStatus(403);
    }

    public function test_attendance_photo_404s_when_there_is_no_photo(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $attendance = JobAttendance::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(),
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => now()->subHours(2), 'check_out_at' => now(),
            'banked_seconds' => 0,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson("/api/v1/attendance/{$attendance->id}/photo")
            ->assertStatus(404);
    }
}
