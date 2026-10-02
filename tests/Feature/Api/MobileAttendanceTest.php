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

    /**
     * A fix a real distance away from {@see onSitePoint()} — used wherever a
     * test needs "somewhere else, but still a plausible GPS reading" rather
     * than the job's own coordinates.
     */
    private function nearbyPoint(float $accuracy = 8.0): array
    {
        return [
            'point' => ['latitude' => 22.720500, 'longitude' => 75.858500, 'accuracy' => $accuracy],
            'method' => 'manual',
        ];
    }

    public function test_check_in_establishes_a_missing_job_location_from_an_accurate_fix(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['latitude' => null, 'longitude' => null]);
        $this->staffJob($job, $user);

        $response = $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->nearbyPoint())
            ->assertStatus(201)
            ->json('data');

        $job->refresh();
        $this->assertEqualsWithDelta(22.720500, (float) $job->latitude, 0.0001);
        $this->assertEqualsWithDelta(75.858500, (float) $job->longitude, 0.0001);
        $this->assertEqualsWithDelta(22.720500, (float) $response['job_latitude'], 0.0001);
        $this->assertEqualsWithDelta(75.858500, (float) $response['job_longitude'], 0.0001);
        // Distance is now computed against the site this same check-in just
        // established, so it reads ~0, not null.
        $this->assertEqualsWithDelta(0.0, (float) $response['check_in_distance_meters'], 0.5);
    }

    public function test_check_in_does_not_establish_a_location_from_a_poor_accuracy_fix(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['latitude' => null, 'longitude' => null]);
        $this->staffJob($job, $user);

        $response = $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->nearbyPoint(80.0))
            ->assertStatus(201)
            ->json('data');

        $job->refresh();
        $this->assertNull($job->latitude);
        $this->assertNull($job->longitude);
        $this->assertNull($response['job_latitude']);
        $this->assertNull($response['job_longitude']);
        $this->assertNull($response['check_in_distance_meters']);
    }

    public function test_check_in_does_not_establish_a_location_from_the_gps_failure_sentinel(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['latitude' => null, 'longitude' => null]);
        $this->staffJob($job, $user);

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [
                'point' => ['latitude' => 0, 'longitude' => 0, 'accuracy' => -1],
                'method' => 'manual',
            ])
            ->assertStatus(201);

        $job->refresh();
        $this->assertNull($job->latitude);
        $this->assertNull($job->longitude);
    }

    public function test_check_in_never_overwrites_an_existing_job_location(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(); // real coordinates already set
        $originalLat = (float) $job->latitude;
        $originalLng = (float) $job->longitude;
        $this->staffJob($job, $user);

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->nearbyPoint())
            ->assertStatus(201);

        $job->refresh();
        $this->assertEqualsWithDelta($originalLat, (float) $job->latitude, 0.0000001);
        $this->assertEqualsWithDelta($originalLng, (float) $job->longitude, 0.0000001);
    }

    public function test_the_first_check_in_wins_when_establishing_a_missing_job_location(): void
    {
        $first = User::factory()->create(['role' => 'Electrician']);
        $second = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['latitude' => null, 'longitude' => null]);
        $this->staffJob($job, $first);
        $this->staffJob($job, $second);

        $this->withHeaders($this->headersFor($first))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->nearbyPoint())
            ->assertStatus(201);
        // `RequestGuard` caches whichever user it resolved for the rest of
        // the test process (real per-request handling never hits this) —
        // forget it before switching to the second technician's token, same
        // as `MobileJobsAndTasksTest`'s own two-user assertions do.
        auth()->forgetGuards();

        // A second technician, checking in moments later against the same
        // never-located job, must not overwrite what the first one just set
        // — simulates the race the `whereNull` guard resolves.
        $this->withHeaders($this->headersFor($second))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", $this->onSitePoint())
            ->assertStatus(201);

        $job->refresh();
        $this->assertEqualsWithDelta(22.720500, (float) $job->latitude, 0.0001);
        $this->assertEqualsWithDelta(75.858500, (float) $job->longitude, 0.0001);
    }

    public function test_an_offline_check_in_and_checkout_keep_the_time_they_happened_and_replay_safely(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $in = now()->subHours(3)->startOfSecond();
        $out = now()->subMinutes(10)->startOfSecond();

        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'method' => 'automatic', 'occurred_at' => $in->toISOString()])
            ->assertCreated()
            ->assertJsonPath('data.recorded_offline', true)
            ->assertJsonPath('data.review_flag', 'delayed_sync');

        $row = JobAttendance::sole();
        $this->assertSame($in->getTimestamp(), $row->check_in_at->getTimestamp());

        // The queue sends the check-in twice: still one record.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'method' => 'automatic', 'occurred_at' => $in->toISOString()])
            ->assertOk();
        $this->assertSame(1, JobAttendance::count());

        $this->app['auth']->forgetGuards();
        $checkout = [...$this->onSitePoint(), 'method' => 'automatic', 'occurred_at' => $out->toISOString()];
        $this->withHeaders($this->headersFor($user))->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", $checkout)->assertOk();
        $this->assertSame($out->getTimestamp(), $row->fresh()->check_out_at->getTimestamp());
        $this->assertEqualsWithDelta(2.83, $row->fresh()->workingSeconds() / 3600, 0.01);

        // And the checkout replayed: same answer, not "you are not checked in".
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->headersFor($user))->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", $checkout)->assertOk();

        // A clock a week behind is refused; one set ahead is clamped to now.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->headersFor($user))
            ->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'occurred_at' => now()->subDays(12)->toISOString()])
            ->assertStatus(422);
    }

    public function test_an_automatic_check_in_can_be_undone_and_a_record_can_be_acknowledged_or_reported(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $h = $this->headersFor($user);

        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'method' => 'automatic'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/corrections", ['kind' => 'ack'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/corrections", ['kind' => 'correction'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/corrections", ['kind' => 'correction', 'message' => 'I was at the supplier, not on site.'])
            ->assertCreated()->assertJsonPath('data.status', 'open');
        $this->assertSame(2, \App\Models\AttendanceCorrection::count());

        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/undo")->assertOk();
        $this->assertSame(0, JobAttendance::count());

        // Undone twice (a replay): harmless.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/undo")->assertOk();
    }

    public function test_the_time_log_lists_a_persons_sessions_with_what_is_flagged_and_offline(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $other = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob(['location' => 'Riverside Project Site']);
        $this->staffJob($job, $user);
        $h = $this->headersFor($user);

        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [
            ...$this->onSitePoint(), 'method' => 'automatic', 'occurred_at' => now()->subHours(2)->toISOString(),
        ])->assertCreated();
        JobAttendance::create(['job_id' => $job->id, 'user_id' => $other->id, 'date' => now()->toDateString(), 'status' => 'checkedIn', 'check_in_at' => now(), 'banked_seconds' => 0]);

        $this->app['auth']->forgetGuards();
        $res = $this->withHeaders($h)->getJson('/api/v1/time-log')->assertOk();
        $res->assertJsonCount(1, 'data.sessions')
            ->assertJsonPath('data.sessions.0.kind', 'attendance')
            ->assertJsonPath('data.sessions.0.source', 'automatic')
            ->assertJsonPath('data.sessions.0.locationVerified', true)
            ->assertJsonPath('data.sessions.0.site', 'Riverside Project Site')
            ->assertJsonPath('data.sessions.0.status', 'on-site')
            ->assertJsonPath('data.sessions.0.recordedOffline', true)
            ->assertJsonPath('data.sessions.0.canReport', true)
            ->assertJsonPath('data.canViewCrew', false);

        // An apprentice reads their own log too.
        $apprentice = User::factory()->create(['role' => 'Apprentice']);
        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->headersFor($apprentice))->getJson('/api/v1/time-log')->assertOk()->assertJsonCount(0, 'data.sessions');
    }

    public function test_an_automatic_check_in_tells_the_technician_in_their_notifications(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $h = $this->headersFor($user);

        // A manual one is their own doing: no notification.
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'method' => 'manual'])->assertCreated();
        $this->assertSame(0, \App\Models\AppNotification::count());
        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/check-out", [...$this->onSitePoint(), 'method' => 'manual'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->postJson("/api/v1/jobs/{$job->id}/attendance/check-in", [...$this->onSitePoint(), 'method' => 'automatic'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($h)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.notifications.0.type', 'attendance-checkin')
            ->assertJsonPath('data.notifications.0.title', 'Checked in automatically')
            ->assertJsonPath('data.notifications.0.data.jobId', $job->id)
            ->assertJsonPath('data.notifications.0.unread', true);
    }
}
