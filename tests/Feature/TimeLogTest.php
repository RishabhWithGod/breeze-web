<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobAttendance;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The time log lists sessions — each check-in cycle and each timer or manual
 * entry — with when it started and ended, how it was recorded, and what needs a
 * look. An open check-in from a finished day is a missing checkout.
 */
class TimeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $tech;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->tech = User::factory()->create(['role' => 'Journeyman', 'name' => 'Sam']);
        $this->job = Job::create([
            'user_id' => $this->manager->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Main Hall',
            'status' => 'in-progress',
        ]);
    }

    private function checkIn(string $date, string $in, ?string $out, string $method = JobAttendance::METHOD_AUTOMATIC): JobAttendance
    {
        return JobAttendance::create([
            'job_id' => $this->job->id,
            'user_id' => $this->tech->id,
            'date' => $date,
            'status' => $out === null ? JobAttendance::STATUS_CHECKED_IN : JobAttendance::STATUS_CHECKED_OUT,
            'check_in_at' => Carbon::parse("$date $in"),
            'check_in_method' => $method,
            'check_out_at' => $out === null ? null : Carbon::parse("$date $out"),
            'check_out_method' => $out === null ? null : $method,
            'client_id' => 'att-'.uniqid(),
        ]);
    }

    private function log(string $query = ''): TestResponse
    {
        return $this->actingAs($this->manager)->get('/time-tracking/entries'.$query);
    }

    public function test_every_session_is_its_own_row_with_its_times_and_source(): void
    {
        $this->checkIn('2026-09-16', '06:58', '15:31');

        TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-17',
            'start_time' => '08:00:00', 'end_time' => '10:00:00', 'hours' => 2,
            'source' => TimeEntry::SOURCE_MANUAL, 'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $this->log()->assertInertia(fn ($page) => $page
            ->has('log.data', 2)
            // Newest first.
            ->where('log.data.0.source', 'Manual entry')
            ->where('log.data.0.status', 'approved')
            ->where('log.data.0.hours', 2)
            ->where('log.data.1.employee.name', 'Sam')
            ->where('log.data.1.employee.role', 'Journeyman')
            ->where('log.data.1.job', 'Main Hall')
            ->where('log.data.1.checkIn.at', '09/16/2026 6:58 AM')
            ->where('log.data.1.checkOut.at', '09/16/2026 3:31 PM')
            ->where('log.data.1.source', 'Auto (Geofence)')
            ->where('log.data.1.status', 'completed'));
    }

    public function test_an_open_check_in_from_an_earlier_day_is_a_missing_checkout(): void
    {
        $this->checkIn(Carbon::yesterday()->toDateString(), '07:12', null);

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.status', 'missing-checkout')
            ->where('log.data.0.checkOut.at', null)
            ->where('log.data.0.hours', null)
            ->where('exceptionCount', 1));
    }

    public function test_an_open_check_in_today_is_on_site_not_an_exception(): void
    {
        $this->checkIn(Carbon::today()->toDateString(), '07:12', null);

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.status', 'on-site')
            ->where('exceptionCount', 0));
    }

    public function test_review_exceptions_narrows_to_missing_checkouts_and_rejected_entries(): void
    {
        $this->checkIn('2026-09-10', '07:00', '15:00');
        $this->checkIn(Carbon::yesterday()->toDateString(), '07:00', null);
        TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-11',
            'hours' => 1, 'source' => TimeEntry::SOURCE_TIMER, 'status' => TimeEntry::STATUS_REJECTED,
        ]);
        TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-12',
            'hours' => 1, 'source' => TimeEntry::SOURCE_TIMER, 'status' => TimeEntry::STATUS_APPROVED,
        ]);

        $this->log()->assertInertia(fn ($page) => $page->has('log.data', 4)->where('exceptionCount', 2));

        $this->log('?exceptions=1')->assertInertia(fn ($page) => $page
            ->has('log.data', 2)
            ->where('log.meta.total', 2)
            ->where('filters.exceptions', true));
    }

    public function test_someone_who_cannot_see_the_crew_sees_only_their_own_sessions(): void
    {
        $this->checkIn('2026-09-16', '07:00', '15:00');

        // Someone who cannot see the crew — an electrician — gets only their own.
        $other = User::factory()->create(['role' => 'Electrician']);

        $this->actingAs($other)
            ->get('/time-tracking/entries')
            ->assertInertia(fn ($page) => $page->has('log.data', 0));
    }

    /* ------------------------------------------------------ approve / check out */

    private function submittedEntry(string $status = TimeEntry::STATUS_SUBMITTED): TimeEntry
    {
        return TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-12',
            'hours' => 2, 'source' => TimeEntry::SOURCE_TIMER, 'status' => $status,
        ]);
    }

    public function test_a_manager_can_approve_a_submitted_entry_but_not_a_draft(): void
    {
        $submitted = $this->submittedEntry();
        $draft = $this->submittedEntry(TimeEntry::STATUS_DRAFT);

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data', fn ($rows) => collect($rows)->firstWhere('entryId', $submitted->id)['canApprove'] === true
                && collect($rows)->firstWhere('entryId', $draft->id)['canApprove'] === false
                && collect($rows)->firstWhere('entryId', $draft->id)['status'] === 'draft'
                && collect($rows)->firstWhere('entryId', $submitted->id)['status'] === 'pending'));

        $this->actingAs($this->manager)
            ->post(route('time-entries.approve', $submitted))
            ->assertSessionHas('success');

        $this->assertSame(TimeEntry::STATUS_APPROVED, $submitted->fresh()->status);
    }

    public function test_a_manager_can_close_a_check_in_nobody_closed(): void
    {
        $open = $this->checkIn(Carbon::yesterday()->toDateString(), '07:00', null);
        $at = Carbon::yesterday()->setTime(15, 30)->format('Y-m-d\TH:i');

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.canCheckOut', true)
            ->where('log.data.0.attendanceId', $open->id));

        $this->actingAs($this->manager)
            ->post(route('attendance.check-out', $open), ['check_out_at' => $at])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $open->refresh();
        $this->assertSame(JobAttendance::STATUS_CHECKED_OUT, $open->status);
        $this->assertSame(JobAttendance::METHOD_MANUAL, $open->check_out_method);
        $this->assertSame(8.5, round($open->workingSeconds() / 3600, 2));

        // Closed, so it is no longer an exception.
        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.status', 'completed')
            ->where('exceptionCount', 0));
    }

    public function test_a_checkout_cannot_precede_the_check_in_or_be_in_the_future(): void
    {
        $open = $this->checkIn(Carbon::yesterday()->toDateString(), '07:00', null);

        $this->actingAs($this->manager)
            ->post(route('attendance.check-out', $open), [
                'check_out_at' => Carbon::yesterday()->setTime(6, 0)->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('check_out_at');

        $this->actingAs($this->manager)
            ->post(route('attendance.check-out', $open), [
                'check_out_at' => Carbon::tomorrow()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('check_out_at');

        $this->assertSame(JobAttendance::STATUS_CHECKED_IN, $open->fresh()->status);
    }

    public function test_only_the_jobs_manager_can_close_a_check_in(): void
    {
        $open = $this->checkIn(Carbon::yesterday()->toDateString(), '07:00', null);
        $at = Carbon::yesterday()->setTime(15, 0)->format('Y-m-d\TH:i');

        // The technician cannot close their own from the log.
        $this->actingAs($this->tech)
            ->post(route('attendance.check-out', $open), ['check_out_at' => $at])
            ->assertForbidden();

        // A manager of somebody else's jobs cannot either.
        $stranger = User::factory()->create(['role' => 'Project Manager']);
        $this->actingAs($stranger)
            ->post(route('attendance.check-out', $open), ['check_out_at' => $at])
            ->assertForbidden();

        $this->assertSame(JobAttendance::STATUS_CHECKED_IN, $open->fresh()->status);
    }

    public function test_a_check_in_on_a_deleted_job_can_still_be_closed_by_its_manager(): void
    {
        $open = $this->checkIn(Carbon::yesterday()->toDateString(), '07:00', null);
        $this->job->delete();

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.job', 'Main Hall')
            ->where('log.data.0.canCheckOut', true));
    }

    /* ------------------------------------------------ a finished session, approved */

    private function finishedDraft(string $date = '2026-09-12'): TimeEntry
    {
        return TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => $date,
            'start_time' => '08:00:00', 'end_time' => '12:00:00', 'hours' => 4,
            'source' => TimeEntry::SOURCE_TIMER, 'status' => TimeEntry::STATUS_DRAFT,
        ]);
    }

    public function test_a_finished_session_can_be_approved_without_the_employee_submitting_it(): void
    {
        $entry = $this->finishedDraft();

        $this->log()->assertInertia(fn ($page) => $page
            ->where('log.data.0.status', 'pending')
            ->where('log.data.0.canApprove', true));

        $this->actingAs($this->manager)
            ->post(route('time-entries.approve', $entry))
            ->assertSessionHas('success');

        $entry->refresh();
        $this->assertSame(TimeEntry::STATUS_APPROVED, $entry->status);
        $this->assertSame($this->manager->id, $entry->approved_by);
        // The trail still reads draft → submitted → approved, not draft → approved.
        $this->assertSame(
            [['draft', 'submitted'], ['submitted', 'approved']],
            $entry->statusChanges()->reorder('id')->get()->map(fn ($c) => [$c->from_status, $c->to_status])->all(),
        );
    }

    public function test_a_session_still_running_cannot_be_approved(): void
    {
        $running = TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-12',
            'start_time' => '08:00:00', 'hours' => 0,
            'source' => TimeEntry::SOURCE_TIMER, 'status' => TimeEntry::STATUS_DRAFT,
        ]);

        $this->actingAs($this->manager)
            ->post(route('time-entries.approve', $running))
            ->assertForbidden();

        $this->assertSame(TimeEntry::STATUS_DRAFT, $running->fresh()->status);
    }

    public function test_the_day_screen_offers_approval_and_approves_every_finished_session(): void
    {
        $one = $this->finishedDraft();
        $two = $this->finishedDraft();
        $running = TimeEntry::create([
            'job_id' => $this->job->id, 'user_id' => $this->tech->id, 'date' => '2026-09-12',
            'start_time' => '13:00:00', 'hours' => 0,
            'source' => TimeEntry::SOURCE_TIMER, 'status' => TimeEntry::STATUS_DRAFT,
        ]);

        $this->actingAs($this->manager)
            ->get(route('time-entries.day', [$this->tech, '2026-09-12']))
            ->assertInertia(function ($page) use ($one, $two) {
                $ids = collect($page->toArray()['props']['day']['approvableEntryIds'])->sort()->values()->all();

                $this->assertSame([$one->id, $two->id], $ids);
            });

        $this->actingAs($this->manager)
            ->post(route('time-entries.day.approve', [$this->tech, '2026-09-12']))
            ->assertSessionHas('success', '2 entries approved.');

        $this->assertSame(TimeEntry::STATUS_APPROVED, $one->fresh()->status);
        $this->assertSame(TimeEntry::STATUS_APPROVED, $two->fresh()->status);
        // The unfinished one is left exactly as it was.
        $this->assertSame(TimeEntry::STATUS_DRAFT, $running->fresh()->status);
    }

    public function test_only_the_jobs_manager_can_approve_a_day(): void
    {
        $this->finishedDraft();

        $stranger = User::factory()->create(['role' => 'Project Manager']);

        $this->actingAs($stranger)
            ->post(route('time-entries.day.approve', [$this->tech, '2026-09-12']))
            ->assertForbidden();

        $this->actingAs($this->tech)
            ->post(route('time-entries.day.approve', [$this->tech, '2026-09-12']))
            ->assertForbidden();
    }

    public function test_the_day_screen_reads_a_check_in_the_way_the_log_does(): void
    {
        $date = Carbon::yesterday()->toDateString();
        $this->checkIn($date, '07:12', null);

        $this->actingAs($this->manager)
            ->get(route('time-entries.day', [$this->tech, $date]))
            ->assertInertia(fn ($page) => $page
                ->where('day.attendance.0.missingCheckout', true)
                ->where('day.attendance.0.checkInLabel', '7:12 AM')
                ->where('day.attendance.0.checkOutLabel', null)
                ->where('day.attendance.0.canCheckOut', true)
                ->where('day.attendance.0.checkOutMin', Carbon::parse("$date 07:12")->format('Y-m-d\TH:i')));

        // A technician looking at their own day cannot close it.
        $this->actingAs($this->tech)
            ->get(route('time-entries.day', [$this->tech, $date]))
            ->assertInertia(fn ($page) => $page->where('day.attendance.0.canCheckOut', false));
    }
}
