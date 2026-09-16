<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Time Log list shows one row per technician per calendar day, not one
 * row per timer session or GPS check-in — a technician who stopped their
 * timer four times, or checked in and out of two jobs, still worked one
 * day. `DailyTimesheetBuilder` is what folds `time_entries` and
 * `job_attendances` into that single row; this pins the grouping itself,
 * separately from either table's own behavior.
 */
class TimeEntryDayGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    private function makeJob(string $name = 'Riverside Office Renovation'): Job
    {
        return Job::create([
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => $name,
            'status' => 'in-progress',
        ]);
    }

    private function makeEntry(Job $job, User $user, string $date, float $hours, string $status = TimeEntry::STATUS_DRAFT): TimeEntry
    {
        return TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => $date,
            'hours' => $hours,
            'source' => TimeEntry::SOURCE_TIMER,
            'status' => $status,
        ]);
    }

    public function test_four_timer_sessions_on_the_same_day_show_as_one_row_with_the_total(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $tech, '2026-08-10', 2.0);
        $this->makeEntry($job, $tech, '2026-08-10', 1.5);
        $this->makeEntry($job, $tech, '2026-08-10', 0.5);
        $this->makeEntry($job, $tech, '2026-08-10', 3.0);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.totalHours', 7)
                ->where('days.data.0.sessionCount', 4));

        // Nothing about the underlying rows changed — the grouping is a
        // presentation layer, not a schema change.
        $this->assertSame(4, TimeEntry::count());
    }

    public function test_a_different_day_is_a_separate_row(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $tech, '2026-08-10', 4.0);
        $this->makeEntry($job, $tech, '2026-08-11', 5.0);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page->has('days.data', 2));
    }

    public function test_sessions_across_two_jobs_the_same_day_still_merge_into_one_row(): void
    {
        $jobA = $this->makeJob('Riverside Office Renovation');
        $jobB = $this->makeJob('Harborview Data Hall');
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($jobA, $tech, '2026-08-10', 3.0);
        $this->makeEntry($jobB, $tech, '2026-08-10', 2.0);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.totalHours', 5)
                ->where('days.data.0.jobs', ['Riverside Office Renovation', 'Harborview Data Hall']));
    }

    public function test_a_timer_entry_and_a_gps_check_in_the_same_day_merge_into_one_row(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $tech, '2026-08-10', 3.0);
        JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $tech->id,
            'date' => '2026-08-10',
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => '2026-08-10 08:00:00',
            'check_out_at' => '2026-08-10 09:00:00',
            'check_in_method' => 'manual',
            'banked_seconds' => 0,
        ]);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.totalHours', 4)
                ->where('days.data.0.sessionCount', 2)
                ->where('days.data.0.hasTimerEntries', true)
                ->where('days.data.0.hasAttendance', true));
    }

    public function test_a_gps_check_in_only_day_has_no_entries_status(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $tech->id,
            'date' => '2026-08-10',
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => '2026-08-10 08:00:00',
            'check_out_at' => '2026-08-10 10:00:00',
            'check_in_method' => 'automatic',
            'banked_seconds' => 0,
        ]);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.status', null)
                ->where('days.data.0.hasTimerEntries', false));
    }

    public function test_any_rejected_session_marks_the_whole_day_rejected(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $tech, '2026-08-10', 3.0, TimeEntry::STATUS_APPROVED);
        $this->makeEntry($job, $tech, '2026-08-10', 1.0, TimeEntry::STATUS_REJECTED);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page->where('days.data.0.status', 'rejected'));
    }

    public function test_a_day_fully_approved_reads_as_approved(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $tech, '2026-08-10', 3.0, TimeEntry::STATUS_APPROVED);
        $this->makeEntry($job, $tech, '2026-08-10', 1.0, TimeEntry::STATUS_APPROVED);

        $this->actingAs($this->manager)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page->where('days.data.0.status', 'approved'));
    }

    public function test_an_electrician_only_sees_their_own_day_rows(): void
    {
        $job = $this->makeJob();
        $me = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($job, $me, '2026-08-10', 3.0);
        $this->makeEntry($job, $someoneElse, '2026-08-10', 5.0);

        $this->actingAs($me)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page
                ->has('days.data', 1)
                ->where('days.data.0.totalHours', 3));
    }

    public function test_the_day_detail_page_lists_every_underlying_session(): void
    {
        $job = $this->makeJob();
        $tech = User::factory()->create(['role' => 'Electrician']);

        $entry1 = $this->makeEntry($job, $tech, '2026-08-10', 2.0);
        $entry2 = $this->makeEntry($job, $tech, '2026-08-10', 1.5);
        $attendance = JobAttendance::create([
            'job_id' => $job->id,
            'user_id' => $tech->id,
            'date' => '2026-08-10',
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => '2026-08-10 08:00:00',
            'check_out_at' => '2026-08-10 09:00:00',
            'check_in_method' => 'manual',
            'banked_seconds' => 0,
        ]);

        $this->actingAs($this->manager)
            ->get("/time-tracking/day/{$tech->id}/2026-08-10")
            ->assertInertia(fn ($page) => $page
                ->component('TimeEntryDayShow')
                ->where('day.userId', $tech->id)
                ->where('day.date', '2026-08-10')
                ->where('day.totalHours', 4.5)
                ->has('day.entries', 2)
                ->has('day.attendance', 1)
                ->where('day.entries.0.id', $entry1->id)
                ->where('day.entries.1.id', $entry2->id)
                ->where('day.attendance.0.id', $attendance->id));
    }

    public function test_the_day_detail_page_breaks_hours_down_by_job(): void
    {
        $jobA = $this->makeJob('Riverside Office Renovation');
        $jobB = $this->makeJob('Harborview Data Hall');
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->makeEntry($jobA, $tech, '2026-08-10', 3.0, TimeEntry::STATUS_APPROVED);
        JobAttendance::create([
            'job_id' => $jobB->id,
            'user_id' => $tech->id,
            'date' => '2026-08-10',
            'status' => JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => '2026-08-10 08:00:00',
            'check_out_at' => '2026-08-10 09:00:00',
            'check_in_method' => 'manual',
            'banked_seconds' => 0,
        ]);

        $this->actingAs($this->manager)
            ->get("/time-tracking/day/{$tech->id}/2026-08-10")
            ->assertInertia(fn ($page) => $page
                ->where('day.status', 'approved')
                ->where('day.jobBreakdown', [
                    ['job' => 'Riverside Office Renovation', 'hours' => 3],
                    ['job' => 'Harborview Data Hall', 'hours' => 1],
                ]));
    }

    public function test_an_electrician_cannot_view_someone_elses_day(): void
    {
        $job = $this->makeJob();
        $me = User::factory()->create(['role' => 'Electrician']);
        $someoneElse = User::factory()->create(['role' => 'Electrician']);
        $this->makeEntry($job, $someoneElse, '2026-08-10', 3.0);

        $this->actingAs($me)
            ->get("/time-tracking/day/{$someoneElse->id}/2026-08-10")
            ->assertForbidden();
    }

    public function test_an_electrician_can_view_their_own_day(): void
    {
        $job = $this->makeJob();
        $me = User::factory()->create(['role' => 'Electrician']);
        $this->makeEntry($job, $me, '2026-08-10', 3.0);

        $this->actingAs($me)
            ->get("/time-tracking/day/{$me->id}/2026-08-10")
            ->assertOk();
    }

    public function test_a_day_with_nothing_recorded_404s(): void
    {
        $tech = User::factory()->create(['role' => 'Electrician']);

        $this->actingAs($this->manager)
            ->get("/time-tracking/day/{$tech->id}/2026-08-10")
            ->assertNotFound();
    }
}
