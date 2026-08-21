<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The one timer a user may have running.
 *
 * The rule worth pinning is that elapsed time is never trusted from the
 * browser — it is always recomputed from `started_at`/`accumulated_seconds`,
 * which is what makes a refresh (or a pause/resume cycle) come back correct.
 */
class TimerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_starting_a_timer_persists_it_and_resolves_a_team_member(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)
            ->post('/time-tracking/timer/start', ['job_id' => $job->id, 'billable' => true])
            ->assertSessionHasNoErrors();

        $session = TimerSession::sole();
        $this->assertSame($this->user->id, $session->user_id);
        $this->assertSame($job->id, $session->job_id);
        $this->assertSame(TimerSession::STATUS_RUNNING, $session->status);

        // Nobody named this user before — a real crew record is created for
        // them, not a fake one.
        $this->assertNotNull($session->team_member_id);
        $this->assertSame($this->user->id, $session->teamMember->user_id);
        $this->assertSame($this->user->name, $session->teamMember->name);
    }

    public function test_a_second_timer_cannot_be_started_while_one_is_active(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $this->actingAs($this->user)
            ->post('/time-tracking/timer/start', ['job_id' => $job->id])
            ->assertSessionHasErrors('timer');

        $this->assertSame(1, TimerSession::count());
    }

    public function test_the_clock_survives_a_refresh_by_recomputing_from_started_at(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $session = TimerSession::sole();
        // Simulates time having genuinely passed since the timer started —
        // exactly what a page refresh an hour later would see.
        $session->update(['started_at' => now()->subMinutes(90)]);

        $elapsed = app(\App\Services\TimeTracking\TimerService::class)->elapsedSeconds($session->fresh());
        $this->assertEqualsWithDelta(90 * 60, $elapsed, 2);
    }

    public function test_pausing_banks_the_elapsed_time_and_resuming_starts_a_fresh_segment(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $session = TimerSession::sole();
        $session->update(['started_at' => now()->subMinutes(30)]);

        $this->actingAs($this->user)->post('/time-tracking/timer/pause')->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame(TimerSession::STATUS_PAUSED, $session->status);
        $this->assertEqualsWithDelta(30 * 60, $session->accumulated_seconds, 2);

        $this->actingAs($this->user)->post('/time-tracking/timer/resume');
        $session->refresh();
        $this->assertSame(TimerSession::STATUS_RUNNING, $session->status);
        // The banked time survives the resume; only the running segment resets.
        $this->assertEqualsWithDelta(30 * 60, $session->accumulated_seconds, 2);
    }

    public function test_stopping_creates_a_draft_entry_and_clears_the_session(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', [
            'job_id' => $job->id,
            'task_label' => 'Panel install',
        ]);

        $session = TimerSession::sole();
        $session->update(['started_at' => now()->subHours(3)]);

        $this->actingAs($this->user)->post('/time-tracking/timer/stop')->assertSessionHasNoErrors();

        $this->assertSame(0, TimerSession::count());

        $entry = TimeEntry::sole();
        $this->assertSame(TimeEntry::STATUS_DRAFT, $entry->status);
        $this->assertSame(TimeEntry::SOURCE_TIMER, $entry->source);
        $this->assertEqualsWithDelta(3.0, (float) $entry->hours, 0.05);
        $this->assertSame('Panel install', $entry->task_label);
        $this->assertSame(1, $entry->activities()->count());
        $this->assertSame(1, $entry->statusChanges()->count());
    }

    public function test_discarding_removes_the_session_without_logging_anything(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $this->actingAs($this->user)->post('/time-tracking/timer/discard')->assertSessionHasNoErrors();

        $this->assertSame(0, TimerSession::count());
        $this->assertSame(0, TimeEntry::count());
    }

    public function test_a_user_cannot_control_someone_elses_timer(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $other = User::factory()->create();
        $this->actingAs($other)->post('/time-tracking/timer/pause')->assertSessionHasErrors('timer');

        $this->assertSame(TimerSession::STATUS_RUNNING, TimerSession::sole()->status);
    }

    /**
     * The running timer is a shared prop, not something only the Time
     * Tracking page knows about — visiting any other screen must still see
     * it, which is what lets the header widget survive navigation.
     */
    public function test_the_running_timer_is_visible_as_a_shared_prop_on_any_page(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $this->actingAs($this->user)->get('/home')
            ->assertInertia(fn ($page) => $page
                ->where('activeTimer.jobId', $job->id)
                ->where('activeTimer.jobName', $job->name)
                ->where('activeTimer.status', TimerSession::STATUS_RUNNING));
    }

    public function test_a_user_with_no_running_timer_sees_a_null_shared_prop(): void
    {
        $this->actingAs($this->user)->get('/home')
            ->assertInertia(fn ($page) => $page->where('activeTimer', null));
    }

    /** One user's timer must never leak into another user's shared props. */
    public function test_the_shared_timer_prop_is_scoped_to_the_signed_in_user(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/timer/start', ['job_id' => $job->id]);

        $other = User::factory()->create();
        $this->actingAs($other)->get('/home')
            ->assertInertia(fn ($page) => $page->where('activeTimer', null));
    }

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
}
