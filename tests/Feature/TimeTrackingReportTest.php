<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Reports over approved time. Only `approved` rows are ever counted — the
 * same rule the dashboard and Job Detail use — so a report can never show
 * hours nobody has actually signed off.
 */
class TimeTrackingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create(['role' => 'Electrician']);
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_reports_are_restricted_to_managers(): void
    {
        $this->actingAs($this->employee)->get('/time-tracking/reports')->assertForbidden();
        $this->actingAs($this->manager)->get('/time-tracking/reports')->assertOk();
    }

    public function test_time_by_job_only_counts_approved_hours_in_range(): void
    {
        $job = $this->makeJob();

        $this->logAndApprove($job, '2026-08-05', 5);
        $this->logAndSubmitOnly($job, '2026-08-06', 9); // not approved — excluded

        $this->actingAs($this->manager)
            ->get('/time-tracking/reports?from=2026-08-01&to=2026-08-31')
            ->assertInertia(fn (Assert $page) => $page
                ->component('TimeTrackingReports')
                ->has('byJob', 1)
                ->where('byJob.0.hours', '5.00'));
    }

    public function test_a_date_outside_the_range_is_excluded(): void
    {
        $job = $this->makeJob();
        $this->logAndApprove($job, '2026-07-01', 5);

        $this->actingAs($this->manager)
            ->get('/time-tracking/reports?from=2026-08-01&to=2026-08-31')
            ->assertInertia(fn (Assert $page) => $page->has('byJob', 0));
    }

    private function logAndApprove(Job $job, string $date, float $hours): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => $date,
            'hours' => $hours,
        ]);
        $entry = TimeEntry::latest('id')->first();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$entry->id}/submit");
        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/approve");

        return $entry->refresh();
    }

    private function logAndSubmitOnly(Job $job, string $date, float $hours): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => $date,
            'hours' => $hours,
        ]);
        $entry = TimeEntry::latest('id')->first();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$entry->id}/submit");

        return $entry->refresh();
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
