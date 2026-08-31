<?php

namespace Tests\Feature\Api;

use App\Events\TimeEntryLogged;
use App\Events\TimeEntrySubmitted;
use App\Events\TimerStateChanged;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Mobile timer and time-entry endpoints.
 *
 * The one thing worth proving above all: these routes call the exact same
 * `TimerService`/`TimeEntryWriteService` the web app calls. There is no
 * mobile-only timer table, no mobile-only time-entry write path, and the
 * service's own locked "one active session per user" check — not anything
 * in this controller — is what makes a duplicate/retried start request
 * safe.
 */
class MobileTimerAndTimeEntryTest extends TestCase
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

    /** Role-based staffing — the simpler of `ElectricianJobAccess`'s two paths, no schedule/task needed. */
    private function staffJob(Job $job, User $user): void
    {
        $job->assignments()->create([
            'role' => 'electrician',
            'name' => $user->name,
            'user_id' => $user->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_starting_a_timer_persists_a_real_timer_session_and_broadcasts(): void
    {
        Event::fake([TimerStateChanged::class]);

        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id, 'billable' => true])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'running');

        $session = TimerSession::sole();
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame($job->id, $session->job_id);
        // The same person the web app would resolve — a real crew record,
        // not a mobile-only concept of "who is running this timer."
        $this->assertNotNull($session->team_member_id);

        Event::assertDispatched(TimerStateChanged::class, fn (TimerStateChanged $e) => $e->userId === $user->id && $e->action === 'started');
    }

    public function test_a_retried_start_request_does_not_create_a_second_active_timer(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson('/api/v1/timer/start', ['job_id' => $job->id])->assertStatus(201);

        // A mobile network retrying the exact same request must not be
        // able to open a second timer — `TimerService::start()`'s locked
        // existence check is what actually prevents this, not any
        // idempotency-key bookkeeping in this controller.
        $this->withHeaders($headers)
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, TimerSession::where('user_id', $user->id)->count());
    }

    public function test_an_electrician_cannot_start_a_timer_on_a_job_they_are_not_staffed_on(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id])
            ->assertStatus(403);

        $this->assertSame(0, TimerSession::count());
    }

    public function test_the_full_start_pause_resume_stop_cycle_works_from_mobile(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson('/api/v1/timer/start', ['job_id' => $job->id])->assertStatus(201);
        $this->withHeaders($headers)->postJson('/api/v1/timer/pause')
            ->assertOk()->assertJsonPath('data.status', 'paused');
        $this->withHeaders($headers)->postJson('/api/v1/timer/resume')
            ->assertOk()->assertJsonPath('data.status', 'running');

        $response = $this->withHeaders($headers)->postJson('/api/v1/timer/stop')->assertOk();

        $this->assertSame(0, TimerSession::count());
        $entryId = $response->json('data.timeEntryId');
        $this->assertDatabaseHas('time_entries', [
            'id' => $entryId,
            'job_id' => $job->id,
            'user_id' => $user->id,
            'source' => TimeEntry::SOURCE_TIMER,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);
    }

    public function test_discarding_a_timer_logs_nothing(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->withHeaders($headers)->postJson('/api/v1/timer/start', ['job_id' => $job->id]);
        $this->withHeaders($headers)->postJson('/api/v1/timer/discard')->assertOk();

        $this->assertSame(0, TimerSession::count());
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_a_user_cannot_pause_someone_elses_timer(): void
    {
        $owner = User::factory()->create(['role' => 'Electrician']);
        $intruder = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/timer/start', ['job_id' => $job->id]);

        // Laravel's `RequestGuard` caches whichever user it resolved for
        // the rest of the test process (real per-request handling never
        // hits this) — without forgetting it, the second call below would
        // silently keep authenticating as $owner regardless of the
        // Authorization header it's actually sent with.
        auth()->forgetGuards();

        // The intruder has no active timer of their own — `activeOrFail()`
        // scopes strictly to the calling user, so there is nothing for
        // them to pause regardless of who else's timer is running.
        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->postJson('/api/v1/timer/pause')
            ->assertStatus(422);

        $this->assertSame(TimerSession::STATUS_RUNNING, TimerSession::where('user_id', $owner->id)->first()->status);
    }

    public function test_show_returns_null_when_there_is_no_active_timer(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/timer')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_creating_a_time_entry_from_mobile_uses_the_shared_write_service_and_broadcasts(): void
    {
        Event::fake([TimeEntryLogged::class]);

        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/time-entries', [
                'job_id' => $job->id,
                'date' => now()->toDateString(),
                'hours' => 4,
                'description' => 'Rough-in wiring, second floor.',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $entry = TimeEntry::sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(4.0, (float) $entry->hours);

        Event::assertDispatched(TimeEntryLogged::class, fn (TimeEntryLogged $e) => $e->entry->id === $entry->id && $e->action === 'created');
    }

    public function test_a_time_entry_missing_both_times_and_hours_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/time-entries', ['job_id' => $job->id, 'date' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_submitting_a_time_entry_from_mobile_broadcasts_and_a_manager_can_then_approve_it_from_web(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $job = $this->makeJob();

        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'hours' => 3,
            'status' => TimeEntry::STATUS_DRAFT,
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        Event::fake([TimeEntrySubmitted::class]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson("/api/v1/time-entries/{$entry->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        Event::assertDispatched(TimeEntrySubmitted::class);
        $this->assertSame(TimeEntry::STATUS_SUBMITTED, $entry->fresh()->status);

        // The approval itself is a manager/web action — not part of the
        // mobile contract — but it operates on the exact same row mobile
        // just wrote, through the exact same web workflow already tested
        // elsewhere (`TimeEntryApprovalTest`).
        $this->actingAs($manager)->post("/time-tracking/entries/{$entry->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame(TimeEntry::STATUS_APPROVED, $entry->fresh()->status);
    }

    public function test_an_electrician_cannot_submit_someone_elses_time_entry(): void
    {
        $owner = User::factory()->create(['role' => 'Electrician']);
        $intruder = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $owner->id,
            'date' => now()->toDateString(),
            'hours' => 2,
            'status' => TimeEntry::STATUS_DRAFT,
            'source' => TimeEntry::SOURCE_MANUAL,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($intruder))
            ->postJson("/api/v1/time-entries/{$entry->id}/submit")
            ->assertStatus(403);

        $this->assertSame(TimeEntry::STATUS_DRAFT, $entry->fresh()->status);
    }

    public function test_the_time_entries_list_only_shows_the_signed_in_users_own_entries(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();

        TimeEntry::create(['job_id' => $job->id, 'user_id' => $user->id, 'date' => now()->toDateString(), 'hours' => 1, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL]);
        TimeEntry::create(['job_id' => $job->id, 'user_id' => $someoneElse->id, 'date' => now()->toDateString(), 'hours' => 5, 'status' => TimeEntry::STATUS_DRAFT, 'source' => TimeEntry::SOURCE_MANUAL]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/time-entries')
            ->assertOk();

        $hours = array_column($response->json('data.entries'), 'hours');
        $this->assertCount(1, $hours);
        $this->assertEquals(1.0, $hours[0]);
    }

    public function test_time_entry_cost_fields_are_never_exposed_to_an_electrician(): void
    {
        $user = User::factory()->create(['role' => 'Electrician']);
        $job = $this->makeJob();
        $this->staffJob($job, $user);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->postJson('/api/v1/time-entries', ['job_id' => $job->id, 'date' => now()->toDateString(), 'hours' => 4])
            ->assertStatus(201)
            ->assertJsonPath('data.laborCost', null)
            ->assertJsonPath('data.billableAmount', null);
    }
}
