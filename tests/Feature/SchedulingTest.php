<?php

namespace Tests\Feature;

use App\Models\CrewShift;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Crew scheduling: the calendar, and the queue of work still to be booked.
 *
 * The rule the whole feature rests on is that "unassigned" means no shift on the
 * calendar — not a status — so booking a crew is what moves a job between the two
 * screens. Most of what follows is that rule from both directions.
 */
class SchedulingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Foreman $foreman;

    private TeamMember $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->foreman = Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);
        $this->lead = TeamMember::create([
            'name' => 'Michael Torres',
            'initials' => 'MT',
            'role' => 'Master Electrician',
        ]);
    }

    /* ------------------------------------------------------------------ routes */

    /**
     * The module lands on the queue, and the calendar sits beneath it.
     *
     * Pinned because the two screens are easy to swap by accident, and the sidebar
     * link, the "Go to Calendar" button and the "View all" link all depend on which
     * of them owns `/scheduling`.
     */
    public function test_the_module_lands_on_the_queue_with_the_calendar_beneath_it(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page->component('SchedulingUnassigned'));

        $this->actingAs($this->user)
            ->get('/scheduling/calendar')
            ->assertInertia(fn (Assert $page) => $page->component('Scheduling'));
    }

    /* --------------------------------------------------------------- calendar */

    public function test_the_calendar_opens_on_the_current_week(): void
    {
        $job = $this->makeJob(['name' => 'Riverside Residence']);
        $this->book($job, Carbon::today());

        $this->actingAs($this->user)
            ->get('/scheduling/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Scheduling')
                ->where('view', 'week')
                ->where('today', Carbon::today()->toDateString())
                // A week view is exactly one row of the grid.
                ->has('days', 7)
                ->has('shifts', 1)
                ->where('shifts.0.jobName', 'Riverside Residence')
                ->where('shifts.0.crew', 'Team A')
                ->where('shifts.0.member.initials', 'MT')
                ->where('shifts.0.startLabel', '8:00 AM'));
    }

    /** A month view is padded to whole weeks, so the grid is always a rectangle. */
    public function test_a_month_view_is_padded_to_whole_weeks(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=month&date=2026-10-15')
            ->assertInertia(function (Assert $page) {
                $page->where('view', 'month')->where('periodLabel', 'October 2026');

                $days = $page->toArray()['props']['days'];

                $this->assertSame(0, count($days) % 7, 'The grid must be whole weeks.');
                $this->assertSame('Sun', $days[0]['weekday']);
                $this->assertSame('Sat', $days[count($days) - 1]['weekday']);

                // The padding days belong to the neighbouring months and are dimmed.
                $this->assertFalse($days[0]['isCurrentPeriod']);
            });
    }

    /** Shifts outside the visible window are not sent — the grid cannot draw them. */
    public function test_only_shifts_inside_the_window_are_returned(): void
    {
        $job = $this->makeJob();
        $this->book($job, Carbon::parse('2026-10-07'));
        $this->book($job, Carbon::parse('2026-11-20'));

        $this->actingAs($this->user)
            ->get('/scheduling/calendar?view=week&date=2026-10-07')
            ->assertInertia(fn (Assert $page) => $page
                ->has('shifts', 1)
                ->where('shifts.0.date', '2026-10-07'));
    }

    public function test_the_calendar_can_be_filtered_to_one_crew(): void
    {
        $job = $this->makeJob();
        $this->book($job, Carbon::today(), ['crew' => 'Team A']);
        $this->book($job, Carbon::today(), ['crew' => 'Team B']);

        $this->actingAs($this->user)
            ->get('/scheduling/calendar?crew=Team+B')
            ->assertInertia(fn (Assert $page) => $page
                ->has('shifts', 1)
                ->where('shifts.0.crew', 'Team B'));
    }

    /* ----------------------------------------------------------- availability */

    public function test_availability_totals_hours_against_capacity(): void
    {
        $job = $this->makeJob();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);
        $this->book($job, $monday, ['duration_hours' => 8]);
        $this->book($job, $monday->copy()->addDay(), ['duration_hours' => 4]);

        $this->actingAs($this->user)
            ->get('/scheduling/availability')
            ->assertInertia(fn (Assert $page) => $page
                ->component('SchedulingAvailability')
                ->where('tab', 'overview')
                ->where('summary.bookedHours', 12)
                ->where('summary.shifts', 2)
                // One crew member, five working days at eight hours.
                ->where('availability.0.hours', 12)
                ->where('availability.0.capacity', 40)
                ->where('availability.0.utilisation', 30));
    }

    /** Booked hours are split by crew, and the shares sum to the whole. */
    public function test_availability_splits_booked_hours_by_crew(): void
    {
        $job = $this->makeJob();
        $today = Carbon::today();
        $this->book($job, $today, ['crew' => 'Team A', 'duration_hours' => 6]);
        $this->book($job, $today, ['crew' => 'Team B', 'duration_hours' => 2]);

        $this->actingAs($this->user)
            ->get('/scheduling/availability')
            ->assertInertia(function (Assert $page) {
                $totals = collect($page->toArray()['props']['crewTotals']);

                // Ordered by hours, so the busiest crew leads.
                $this->assertSame('Team A', $totals[0]['crew']);
                $this->assertSame(6.0, (float) $totals[0]['hours']);
                $this->assertSame(75.0, (float) $totals[0]['share']);
                $this->assertSame(25.0, (float) $totals[1]['share']);
                $this->assertSame(100.0, (float) $totals->sum('share'));
            });
    }

    /**
     * Only genuine overlaps count as a clash.
     *
     * Two bookings in one day are normal — a morning job and an afternoon one. A
     * clash is the second starting before the first has finished.
     */
    public function test_availability_reports_overlapping_shifts_only(): void
    {
        $job = $this->makeJob();
        $today = Carbon::today();

        // Back to back: 8am–12pm then 1pm. Not a clash.
        $this->book($job, $today, ['start_time' => '08:00:00', 'duration_hours' => 4]);
        $this->book($job, $today, ['start_time' => '13:00:00', 'duration_hours' => 4]);

        $this->actingAs($this->user)
            ->get('/scheduling/availability?tab=conflicts')
            ->assertInertia(fn (Assert $page) => $page->has('conflicts', 0));

        // A second day, with one genuine overlap: 9am–5pm against a 1pm start.
        $tomorrow = $today->copy()->addDay();
        $this->book($job, $tomorrow, ['start_time' => '09:00:00', 'duration_hours' => 8]);
        $this->book($job, $tomorrow, ['start_time' => '13:00:00', 'duration_hours' => 2]);

        $this->actingAs($this->user)
            ->get('/scheduling/availability?tab=conflicts')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'conflicts')
                ->has('conflicts', 1)
                ->where('conflicts.0.member.initials', 'MT')
                ->where('conflicts.0.overlapMinutes', 240));
    }

    /**
     * A long shift clashes with a later one even when the shift between them does not.
     *
     * Comparing only neighbouring shifts would report the 9am clash and miss the 4pm
     * one entirely, which is the booking most likely to be a real problem.
     */
    public function test_availability_catches_a_clash_that_is_not_with_the_next_shift(): void
    {
        $job = $this->makeJob();
        $today = Carbon::today();

        $this->book($job, $today, ['start_time' => '08:00:00', 'duration_hours' => 10]);
        $this->book($job, $today, ['start_time' => '09:00:00', 'duration_hours' => 1]);
        $this->book($job, $today, ['start_time' => '16:00:00', 'duration_hours' => 2]);

        $this->actingAs($this->user)
            ->get('/scheduling/availability?tab=conflicts')
            ->assertInertia(function (Assert $page) {
                $conflicts = collect($page->toArray()['props']['conflicts']);

                // 8am–6pm clashes with both; 9am–10am clears the 4pm start.
                $this->assertCount(2, $conflicts);
                // Canonicalised: these are clock times, and sorting them as strings
                // would put "4:00 PM" before "9:00 AM".
                $this->assertEqualsCanonicalizing(
                    ['9:00 AM', '4:00 PM'],
                    $conflicts->pluck('second.startLabel')->all(),
                );
            });
    }

    /** Booking past capacity is called out rather than left as a full bar. */
    public function test_availability_flags_an_overbooked_crew_member(): void
    {
        $job = $this->makeJob();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);

        // 48 hours against a 40-hour week.
        for ($day = 0; $day < 6; $day++) {
            $this->book($job, $monday->copy()->addDays($day), ['duration_hours' => 8]);
        }

        $this->actingAs($this->user)
            ->get('/scheduling/availability')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.overbooked', 1)
                ->where('availability.0.isOverbooked', true)
                // Clamped, so the bar cannot overflow its track.
                ->where('availability.0.utilisation', 100));
    }

    public function test_availability_lists_each_members_shifts(): void
    {
        $job = $this->makeJob(['name' => 'Riverside Residence']);
        $this->book($job, Carbon::today());

        $this->actingAs($this->user)
            ->get('/scheduling/availability')
            ->assertInertia(function (Assert $page) {
                $assignments = $page->toArray()['props']['assignments'];
                $mine = $assignments[$this->lead->id];

                $this->assertCount(1, $mine);
                $this->assertSame('Riverside Residence', $mine[0]['jobName']);
                $this->assertSame('8:00 AM', $mine[0]['startLabel']);
                $this->assertSame('4:00 PM', $mine[0]['endLabel']);
            });
    }

    /** The window is the calendar's, so both screens count the same days. */
    public function test_availability_honours_the_calendar_window(): void
    {
        $job = $this->makeJob();
        $this->book($job, Carbon::parse('2026-10-07'));
        $this->book($job, Carbon::parse('2026-11-20'));

        $this->actingAs($this->user)
            ->get('/scheduling/availability?view=week&date=2026-10-07')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.shifts', 1)
                // Both ends written out, month first — see UsDateFormatTest.
                ->where('periodLabel', '10/04/2026 – 10/10/2026'));
    }

    /* ------------------------------------------------------------- unassigned */

    /**
     * The defining rule: a job is unassigned until a crew is booked on it.
     *
     * Its status is irrelevant — a job can read `scheduled` because an estimate was
     * signed off and still have nobody going to site.
     */
    public function test_unassigned_means_no_shift_rather_than_a_status(): void
    {
        $booked = $this->makeJob(['name' => 'Has A Crew', 'status' => 'planning']);
        $this->book($booked, Carbon::today());

        $this->makeJob(['name' => 'Says Scheduled But Is Not', 'status' => 'scheduled']);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->component('SchedulingUnassigned')
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Says Scheduled But Is Not'));
    }

    /** Completed and archived work has nothing left to book. */
    public function test_completed_and_archived_jobs_are_not_in_the_queue(): void
    {
        $this->makeJob(['name' => 'Open']);
        $this->makeJob(['name' => 'Finished', 'status' => 'completed']);
        $this->makeJob(['name' => 'Filed Away', 'archived_at' => now()]);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Open'));
    }

    /**
     * Priority is a word, so it cannot be ordered alphabetically.
     *
     * Sorted that way "high" would come before "low" and "medium" by accident
     * rather than by rank, which is the wrong order two thirds of the time.
     */
    public function test_the_queue_is_ranked_by_priority_not_alphabetically(): void
    {
        $this->makeJob(['name' => 'Low Job', 'priority' => 'low']);
        $this->makeJob(['name' => 'High Job', 'priority' => 'high']);
        $this->makeJob(['name' => 'Medium Job', 'priority' => 'medium']);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'priority-desc')
                ->where('jobs.data.0.name', 'High Job')
                ->where('jobs.data.1.name', 'Medium Job')
                ->where('jobs.data.2.name', 'Low Job'));

        $this->actingAs($this->user)
            ->get('/scheduling?sort=priority-asc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.name', 'Low Job')
                ->where('jobs.data.2.name', 'High Job'));
    }

    public function test_the_queue_can_be_filtered_by_type_and_searched(): void
    {
        $this->makeJob(['name' => 'Skyline Office', 'job_type' => 'commercial']);
        $this->makeJob(['name' => 'Thompson Residence', 'job_type' => 'residential']);

        $this->actingAs($this->user)
            ->get('/scheduling?type=residential')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Thompson Residence'));

        $this->actingAs($this->user)
            ->get('/scheduling?search=Skyline')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Skyline Office'));
    }

    /**
     * The tab counts describe the whole queue, not the tab being viewed.
     *
     * Counting them through the type filter would make every tab read as the one
     * already selected.
     */
    public function test_type_counts_ignore_the_selected_tab(): void
    {
        $this->makeJob(['job_type' => 'commercial']);
        $this->makeJob(['job_type' => 'commercial']);
        $this->makeJob(['job_type' => 'residential']);

        $this->actingAs($this->user)
            ->get('/scheduling?type=residential')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.all', 3)
                ->where('counts.commercial', 2)
                ->where('counts.residential', 1));
    }

    /* ---------------------------------------------------------------- booking */

    public function test_booking_a_crew_moves_a_job_off_the_queue(): void
    {
        $job = $this->makeJob(['name' => 'Emergency Generator Repair', 'status' => 'planning']);

        $this->actingAs($this->user)
            ->from('/scheduling')
            ->post('/scheduling/schedules', [
                'job_id' => $job->id,
                'team_member_id' => $this->lead->id,
                'crew' => 'Team C',
                'scheduled_date' => '2026-10-05',
                'start_time' => '08:00',
                'duration_hours' => 8,
                'days' => 1,
            ])
            ->assertRedirect('/scheduling')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('crew_shifts', [
            'job_id' => $job->id,
            'crew' => 'Team C',
            'scheduled_date' => '2026-10-05',
        ]);

        // Booked work is scheduled work, on every screen.
        $this->assertSame('scheduled', $job->fresh()->status);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page->has('jobs.data', 0));
    }

    /** A multi-day booking is one row per day, so a crew can change mid-job. */
    public function test_a_multi_day_booking_writes_one_shift_per_working_day(): void
    {
        $job = $this->makeJob();

        // Thursday: three working days runs Thu, Fri, then Monday.
        $this->actingAs($this->user)
            ->post('/scheduling/schedules', [
                'job_id' => $job->id,
                'crew' => 'Team A',
                'scheduled_date' => '2026-10-08',
                'start_time' => '08:00',
                'duration_hours' => 8,
                'days' => 3,
            ])
            ->assertSessionHas('success');

        $dates = CrewShift::query()
            ->where('job_id', $job->id)
            ->orderBy('scheduled_date')
            ->pluck('scheduled_date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $this->assertSame(['2026-10-08', '2026-10-09', '2026-10-12'], $dates);
    }

    /** Work already under way keeps its own status when a crew is added. */
    public function test_booking_does_not_overwrite_the_status_of_live_work(): void
    {
        $job = $this->makeJob(['status' => 'in-progress']);

        $this->actingAs($this->user)->post('/scheduling/schedules', [
            'job_id' => $job->id,
            'crew' => 'Team A',
            'scheduled_date' => '2026-10-05',
            'start_time' => '08:00',
            'duration_hours' => 8,
        ]);

        $this->assertSame('in-progress', $job->fresh()->status);
    }

    public function test_a_shift_can_be_moved_and_reassigned(): void
    {
        $job = $this->makeJob();
        $shift = $this->book($job, Carbon::parse('2026-10-05'));

        $this->actingAs($this->user)
            ->put("/scheduling/schedules/{$shift->id}", [
                'crew' => 'Team B',
                'scheduled_date' => '2026-10-06',
                'start_time' => '13:00',
                'duration_hours' => 4,
                'status' => 'confirmed',
            ])
            ->assertSessionHas('success');

        $shift->refresh();

        $this->assertSame('Team B', $shift->crew);
        $this->assertSame('2026-10-06', $shift->scheduled_date->toDateString());
        $this->assertSame('1:00 PM', $shift->startLabel());
        $this->assertSame('5:00 PM', $shift->endLabel());
        $this->assertSame('confirmed', $shift->status);
    }

    /** Removing the last shift is what puts a job back in the queue. */
    public function test_removing_the_last_shift_returns_the_job_to_the_queue(): void
    {
        $job = $this->makeJob(['name' => 'Back To The Queue']);
        $shift = $this->book($job, Carbon::today());

        $this->actingAs($this->user)
            ->delete("/scheduling/schedules/{$shift->id}")
            ->assertSessionHas('warning');

        $this->assertDatabaseMissing('crew_shifts', ['id' => $shift->id]);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->has('jobs.data', 1)
                ->where('jobs.data.0.name', 'Back To The Queue'));
    }

    public function test_booking_rejects_an_unknown_job(): void
    {
        $this->actingAs($this->user)
            ->post('/scheduling/schedules', [
                'job_id' => 99999,
                'crew' => 'Team A',
                'scheduled_date' => '2026-10-05',
                'start_time' => '08:00',
                'duration_hours' => 8,
            ])
            ->assertSessionHasErrors('job_id');
    }

    public function test_scheduling_requires_signing_in(): void
    {
        $this->get('/scheduling')->assertRedirect('/login');
        $this->get('/scheduling/calendar')->assertRedirect('/login');
    }

    /* --------------------------------------------------------------- helpers */

    /** @param  array<string, mixed>  $attributes */
    private function makeJob(array $attributes = []): Job
    {
        return Job::create([
            'foreman_id' => $this->foreman->id,
            'name' => 'Test Job',
            'client' => 'Riverside Hospital',
            'location' => '2580 Market Street, Philadelphia',
            'job_type' => 'commercial',
            'status' => 'planning',
            'priority' => 'medium',
            'estimated_hours' => 24,
            'required_skills' => ['Commercial Electric', 'Panel Installation'],
            'budget' => 14850,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    /** A task on the job, with the foreman it is assigned to. */
    private function task(Job $job, string $title, Foreman $foreman, int $position = 0): void
    {
        $schedule = $job->schedule ?? JobSchedule::create([
            'job_id' => $job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        $job->setRelation('schedule', $schedule);

        $job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => $title,
            'position' => $position,
            'foreman_id' => $foreman->id,
        ]);
    }

    private function book(Job $job, Carbon $date, array $attributes = []): CrewShift
    {
        return CrewShift::create([
            'job_id' => $job->id,
            'team_member_id' => $this->lead->id,
            'crew' => 'Team A',
            'scheduled_date' => $date->toDateString(),
            'start_time' => '08:00:00',
            'duration_hours' => 8,
            'status' => CrewShift::STATUS_SCHEDULED,
            ...$attributes,
        ]);
    }

    /* ------------------------------------------------- booking a job's own crew */

    /**
     * Nobody is picked at the booking.
     *
     * Foremen are assigned when a job's work is broken into tasks. Asking again
     * at the modal invited a second, different answer — the calendar saying one
     * thing and the task list another — so the shift takes whoever is already on
     * the job.
     */
    public function test_a_booking_is_labelled_with_the_foremen_on_the_job(): void
    {
        $job = $this->makeJob(['foreman_id' => null]);
        $luis = Foreman::create(['name' => 'Luis Ortega', 'initials' => 'LO']);

        $this->task($job, 'Rough-in', $this->foreman, 0);
        $this->task($job, 'Trim out', $luis, 1);

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
                'days' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Dana Wu, Luis Ortega', CrewShift::sole()->crew);
    }

    /** One foreman on two tasks is one name, not two. */
    public function test_a_foreman_on_several_tasks_is_named_once(): void
    {
        $job = $this->makeJob(['foreman_id' => null]);
        $this->task($job, 'Rough-in', $this->foreman, 0);
        $this->task($job, 'Trim out', $this->foreman, 1);

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Dana Wu', CrewShift::sole()->crew);
    }

    /**
     * A job whose work has not been broken down yet has nobody on it, and says
     * so. Inventing a crew name would put a stranger on the calendar.
     */
    public function test_a_job_with_nobody_on_it_books_as_unassigned(): void
    {
        $job = $this->makeJob(['foreman_id' => null]);

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Unassigned', CrewShift::sole()->crew);
    }

    /** Jobs raised before foremen moved to tasks still carry one of their own. */
    public function test_an_older_job_falls_back_to_its_own_foreman(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Dana Wu', CrewShift::sole()->crew);
    }

    /**
     * The queue carries what the booking form fills itself in from: who is on
     * the job, and the days it is meant to run.
     */
    public function test_the_queue_carries_the_dates_and_foremen_the_form_starts_from(): void
    {
        $job = $this->makeJob([
            'foreman_id' => null,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-11',
        ]);
        $this->task($job, 'Rough-in', $this->foreman);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.foremen.0.name', 'Dana Wu')
                ->where('jobs.data.0.foremen.0.initials', 'DW')
                // Both dates, so the form can count the working days between.
                ->has('jobs.data.0.startDate')
                ->has('jobs.data.0.endDate'));
    }

    /** The queue no longer ships crew or member lists — nothing picks from them. */
    public function test_the_queue_does_not_ship_lists_nobody_picks_from(): void
    {
        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->missing('crews')
                ->missing('members'));
    }

    /* ------------------------------------------------ the crew, on schedule */

    /**
     * A shift is booked for a team, so that is what it is labelled with.
     *
     * The label used to be the foremen's names. Those change after a booking;
     * the crew the job was handed to does not.
     */
    public function test_a_booking_is_labelled_with_the_jobs_team(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $job = $this->makeJob(['foreman_id' => null, 'team_id' => $north->id]);
        $this->task($job, 'Rough-in', $this->foreman);

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('North Crew', CrewShift::sole()->crew);
    }

    /** A job with no crew still gets a useful label, from whoever is on it. */
    public function test_a_job_with_no_team_is_labelled_with_its_people(): void
    {
        $job = $this->makeJob(['foreman_id' => null]);
        $this->task($job, 'Rough-in', $this->foreman);

        $this->actingAs($this->user)
            ->post(route('scheduling.store'), [
                'job_id' => $job->id,
                'scheduled_date' => Carbon::parse('next monday')->toDateString(),
                'start_time' => '08:00',
                'duration_hours' => 8,
            ]);

        $this->assertSame('Dana Wu', CrewShift::sole()->crew);
    }

    public function test_the_queue_names_the_crew_and_both_roles(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $torres = Foreman::create([
            'name' => 'Michael Torres', 'initials' => 'MT',
            'team_id' => $north->id, 'role' => 'supervisor',
        ]);
        $job = $this->makeJob(['foreman_id' => null, 'team_id' => $north->id]);

        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        $job->tasks()->create([
            'job_schedule_id' => $schedule->id,
            'title' => 'Rough-in',
            'position' => 0,
            'foreman_id' => $this->foreman->id,
            'supervisor_id' => $torres->id,
        ]);

        $this->actingAs($this->user)
            ->get('/scheduling')
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.teamName', 'North Crew')
                ->where('jobs.data.0.foremen.0.name', 'Dana Wu')
                ->where('jobs.data.0.supervisors.0.name', 'Michael Torres'));
    }

    /**
     * A block on the calendar names who is on the work now, not who was on it
     * when the booking was made.
     */
    public function test_a_calendar_block_names_who_is_on_the_work(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $job = $this->makeJob(['foreman_id' => null, 'team_id' => $north->id]);
        $this->task($job, 'Rough-in', $this->foreman);
        $this->book($job, Carbon::today());

        $this->actingAs($this->user)
            ->get('/scheduling/calendar')
            ->assertInertia(fn (Assert $page) => $page
                ->where('shifts.0.teamName', 'North Crew')
                ->where('shifts.0.foremen.0.name', 'Dana Wu'));
    }

    /**
     * The team filter offers the register, not three crews that never existed.
     *
     * Labels already on shifts are kept: a filter that cannot select what is on
     * the calendar is a filter that lies.
     */
    public function test_the_team_filter_offers_the_real_register(): void
    {
        Team::create(['name' => 'North Crew']);
        $this->book($this->makeJob(), Carbon::today(), ['crew' => 'Old Label']);

        $this->actingAs($this->user)
            ->get('/scheduling/calendar')
            ->assertInertia(function (Assert $page) {
                $crews = $page->toArray()['props']['crews'];

                $this->assertContains('North Crew', $crews);
                $this->assertContains('Old Label', $crews);
                $this->assertNotContains('Team B', $crews);
            });
    }
}
