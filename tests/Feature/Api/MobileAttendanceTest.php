<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server side of Breeze-Electric's GPS check-in/check-out feature.
 *
 * The rules worth pinning: a check-in never trusts the client's own
 * distance figure (it is always re-derived from the job's stored
 * coordinates), an already-checked-in check-in is a no-op 200 rather than
 * a duplicate row, and a same-day re-check-in resumes the running total
 * instead of restarting it — the exact accumulation
 * `JobSiteAttendance.workingDuration` does on the mobile side.
 */
class MobileAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            // A real site, ~111m north of the technician's claimed fix
            // below (1 degree of latitude is ~111.32km, so 0.001deg ~111m)
            // — close enough to a 100m default radius to make "inside" vs
            // "far away" meaningfully different in these tests.
            'latitude' => 22.719568,
            'longitude' => 75.857727,
            ...$attributes,
        ]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function staffJob(Job $job, User $user): void
    {
        $job->assignments()->create([
            'role' => 'electrician',
            'name' => $user->name,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    private function headersFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($user)];
    }

    /** A fix right on the job's own coordinates — genuinely "at the site". */
    private function onSitePoint(): array
    {
        return [
            'point' => ['latitude' => 22.719568, 'longitude' => 75.857727, 'accuracy' => 8.0],
            'distance_meters' => 999999, // deliberately wrong — the server must ignore this
            'method' => 'manual',
        ];
    }

    public function test_checking_in_creates_a_record_and_ignores_the_clients_claimed_distance(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'checkedIn')
            ->assertJsonPath('data.check_in_method', 'manual');

        $row = JobAttendance::sole();
        $this->assertSame($job->id, $row->job_id);
        $this->assertSame($user->id, $row->user_id);
        $this->assertNotNull($row->check_in_at);
        // The client claimed 999999m; the real distance between two
        // identical coordinates is 0 — proving the server recomputed it
        // rather than trusting the request body.
        $this->assertEqualsWithDelta(0.0, (float) $row->check_in_distance_meters, 0.5);
    }

    public function test_an_already_checked_in_check_in_is_a_no_op_200_not_a_duplicate(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = $this->headersFor($user);

        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(201);
        $firstCheckInAt = JobAttendance::sole()->check_in_at;

        // A retried request — network flake after the first one actually
        // landed — must not create a second row or move the check-in time.
        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'checkedIn');

        $this->assertSame(1, JobAttendance::count());
        $this->assertTrue($firstCheckInAt->equalTo(JobAttendance::sole()->check_in_at));
    }

    public function test_checking_out_without_being_checked_in_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", $this->onSitePoint())
            ->assertStatus(422);

        $this->assertSame(0, JobAttendance::count());
    }

    public function test_checking_out_completes_the_record(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = $this->headersFor($user);

        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint());

        $this->withHeaders($headers)
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", $this->onSitePoint())
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'checkedOut');

        $row = JobAttendance::sole();
        $this->assertSame('checkedOut', $row->status);
        $this->assertNotNull($row->check_out_at);
    }

    public function test_a_same_day_recheckin_resumes_the_running_total(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = $this->headersFor($user);

        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint());
        // Back-date the check-in so the first cycle has a real, non-zero
        // duration once checked out a moment later.
        JobAttendance::sole()->update(['check_in_at' => now()->subHour()]);

        $this->withHeaders($headers)
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", $this->onSitePoint())
            ->assertStatus(200);

        $afterFirstCheckout = JobAttendance::sole();
        $this->assertSame(0, $afterFirstCheckout->banked_seconds);
        $this->assertGreaterThanOrEqual(3599, $afterFirstCheckout->workingSeconds());

        $reCheckIn = $this->withHeaders($headers)
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(201)
            ->json('data');

        // Still one row for this job/user/day — reused, not a second row.
        $this->assertSame(1, JobAttendance::count());
        $this->assertSame('checkedIn', $reCheckIn['status']);
        $this->assertGreaterThanOrEqual(3599, $reCheckIn['banked_duration_seconds']);
    }

    public function test_a_gps_failure_sentinel_is_stored_as_no_point_not_as_zero_zero(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        // The app's own sentinel for "GPS failed but check-in must still
        // succeed" — see `AttendanceController.checkInWithPhoto` on mobile.
        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [
                'point' => ['latitude' => 0, 'longitude' => 0, 'accuracy' => -1],
                'method' => 'manual',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.check_in_point', null)
            ->assertJsonPath('data.check_in_distance_meters', null);

        $row = JobAttendance::sole();
        $this->assertNull($row->check_in_lat);
        $this->assertNull($row->check_in_distance_meters);
    }

    public function test_an_unstaffed_technician_cannot_check_in(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        // Deliberately not staffed on this job.

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(403);
    }

    public function test_index_returns_null_when_nothing_is_checked_in_today(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeaders($this->headersFor($user))
            ->getJson("/api/v1/jobs/{$job->id}/attendance")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    public function test_today_lists_records_across_every_job(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $jobOne = $this->makeJob(['name' => 'Job One']);
        $jobTwo = $this->makeJob(['name' => 'Job Two']);
        $this->staffJob($jobOne, $user);
        $this->staffJob($jobTwo, $user);
        $headers = $this->headersFor($user);

        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$jobOne->id}/attendance/check-in", $this->onSitePoint());
        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$jobOne->id}/attendance/check-out", $this->onSitePoint());
        $this->withHeaders($headers)->postJson("/api/v1/jobs/{$jobTwo->id}/attendance/check-in", $this->onSitePoint());

        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/attendance/today')
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data'));
    }
}
