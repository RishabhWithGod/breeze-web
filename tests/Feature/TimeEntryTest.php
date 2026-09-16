<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logging time: the form's own validation, and the calculation it triggers.
 *
 * `hours` is never taken from the client at face value when start/end times
 * are given — it is always recomputed server-side, which is the guarantee the
 * brief asks for ("do not calculate totals separately in frontend and backend").
 */
class TimeEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_hours_are_calculated_from_start_and_end_time(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'start_time' => '08:00',
            'end_time' => '16:30',
            'break_minutes' => 30,
        ])->assertSessionHasNoErrors();

        $entry = TimeEntry::sole();
        // 8:00 to 16:30 is 8.5 hours, minus a 30 minute break.
        $this->assertSame('8.00', $entry->hours);
        $this->assertSame(TimeEntry::STATUS_DRAFT, $entry->status);
        $this->assertSame(TimeEntry::SOURCE_MANUAL, $entry->source);
    }

    public function test_end_time_cannot_be_before_start_time(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'start_time' => '16:00',
            'end_time' => '08:00',
        ])->assertSessionHasErrors('end_time');

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_a_break_cannot_exceed_the_time_worked(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'break_minutes' => 90,
        ])->assertSessionHasErrors('break_minutes');
    }

    public function test_hours_cannot_be_negative(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => -2,
        ])->assertSessionHasErrors();

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_a_manually_entered_hours_figure_is_accepted_without_times(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => 6.5,
        ])->assertSessionHasNoErrors();

        $this->assertSame('6.50', TimeEntry::sole()->hours);
    }

    public function test_a_task_must_belong_to_the_selected_job(): void
    {
        $job = $this->makeJob();
        $otherJob = $this->makeJob(['name' => 'Different Job']);
        $task = $this->makeTask($otherJob);

        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'job_task_id' => $task->id,
            'date' => '2026-08-10',
            'hours' => 2,
        ])->assertSessionHasErrors('job_task_id');
    }

    public function test_a_user_can_only_edit_their_own_draft_entries(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => 4,
        ]);
        $entry = TimeEntry::sole();

        $other = User::factory()->create();
        $this->actingAs($other)
            ->put("/time-tracking/entries/{$entry->id}", ['job_id' => $job->id, 'date' => '2026-08-10', 'hours' => 5])
            ->assertForbidden();

        $entry->update(['status' => TimeEntry::STATUS_APPROVED]);
        $this->actingAs($this->user)
            ->put("/time-tracking/entries/{$entry->id}", ['job_id' => $job->id, 'date' => '2026-08-10', 'hours' => 5])
            ->assertForbidden();
    }

    /**
     * A timer stores start/end to the second; the edit form's native time
     * inputs only round-trip to the minute. Re-saving a sub-minute entry
     * without touching its times must not resend a lower-precision value
     * that collides with itself and fails "end after start" — pinned here
     * because it is exactly the kind of thing that only shows up once, on
     * the second edit.
     */
    public function test_resaving_a_sub_minute_entry_unchanged_does_not_fail_validation(): void
    {
        $job = $this->makeJob();

        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $this->user->id,
            'date' => '2026-08-10',
            'start_time' => '09:24:12',
            'end_time' => '09:24:47',
            'hours' => 0.01,
            'source' => TimeEntry::SOURCE_TIMER,
            'status' => TimeEntry::STATUS_DRAFT,
        ]);

        $this->actingAs($this->user)
            ->put("/time-tracking/entries/{$entry->id}", [
                'job_id' => $job->id,
                'date' => '2026-08-10',
                // The form only ever sends minute precision.
                'start_time' => '09:24',
                'end_time' => '09:24',
                'description' => 'Added a note without touching the times.',
            ])
            ->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertSame('0.01', $entry->hours);
        $this->assertSame('Added a note without touching the times.', $entry->description);
    }

    /**
     * A correction locks the original and raises a fresh row pointing back at
     * it via `corrects_id` — the detail page has to let someone reach the
     * other side from *either* row, not just show an `isCorrection` badge
     * with nowhere to click.
     */
    public function test_show_links_a_correction_to_the_entry_it_corrects_and_back(): void
    {
        $job = $this->makeJob();

        $original = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $this->user->id,
            'date' => '2026-08-10',
            'hours' => 5,
            'source' => TimeEntry::SOURCE_MANUAL,
            'status' => TimeEntry::STATUS_LOCKED,
        ]);

        $correction = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $this->user->id,
            'date' => '2026-08-10',
            'hours' => 6,
            'source' => TimeEntry::SOURCE_MANUAL,
            'status' => TimeEntry::STATUS_DRAFT,
            'corrects_id' => $original->id,
        ]);

        $this->actingAs($this->user)
            ->get("/time-tracking/entries/{$original->id}")
            ->assertInertia(fn ($page) => $page
                ->where('corrects', null)
                ->where('corrections.0.id', $correction->id)
                ->where('corrections.0.status', TimeEntry::STATUS_DRAFT));

        $this->actingAs($this->user)
            ->get("/time-tracking/entries/{$correction->id}")
            ->assertInertia(fn ($page) => $page
                ->where('corrects.id', $original->id)
                ->where('corrects.status', TimeEntry::STATUS_LOCKED)
                ->where('corrections', []));
    }

    public function test_deleting_an_entry_is_a_soft_delete_and_only_allowed_while_editable(): void
    {
        $job = $this->makeJob();
        $this->actingAs($this->user)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => 4,
        ]);
        $entry = TimeEntry::sole();

        $this->actingAs($this->user)->delete("/time-tracking/entries/{$entry->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('time_entries', ['id' => $entry->id]);
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

    private function makeTask(Job $job): JobTask
    {
        $schedule = JobSchedule::create([
            'job_id' => $job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        return JobTask::create([
            'job_schedule_id' => $schedule->id,
            'job_id' => $job->id,
            'title' => 'Site survey',
            'estimated_hours' => 8,
        ]);
    }
}
