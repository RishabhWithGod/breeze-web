<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeTracking\JobLaborSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Time Tracking's numbers as Job Detail (and, eventually, Job Costing) would
 * read them: estimated vs actual hours, remaining, and labor cost — always a
 * live aggregate over approved entries, never a stored duplicate.
 */
class TimeTrackingJobIntegrationTest extends TestCase
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

    public function test_the_summary_only_counts_approved_hours(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 20);

        $this->logAndApprove($job, hours: 6, billable: true);
        $this->logAndSubmitOnly($job, hours: 3); // still pending — should not count yet

        $summary = app(JobLaborSummary::class)->for($job->fresh());

        $this->assertSame(20.0, $summary['estimatedHours']);
        $this->assertSame(6.0, $summary['actualHours']);
        $this->assertSame(6.0, $summary['billableHours']);
        $this->assertSame(14.0, $summary['remainingHours']);
        $this->assertSame(3.0, $summary['pendingApprovalHours']);
    }

    public function test_a_correction_transfers_the_counted_hours_to_the_new_entry(): void
    {
        $job = $this->makeJob();

        $entry = $this->logAndApprove($job, hours: 8, billable: true);
        $this->assertSame(8.0, app(JobLaborSummary::class)->for($job->fresh())['actualHours']);

        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/reopen", ['reason' => 'Adjusting hours.']);
        $this->assertSame(0.0, app(JobLaborSummary::class)->for($job->fresh())['actualHours']);

        $correction = TimeEntry::where('corrects_id', $entry->id)->sole();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$correction->id}/submit");
        $this->actingAs($this->manager)->post("/time-tracking/entries/{$correction->id}/approve");

        $this->assertSame(8.0, app(JobLaborSummary::class)->for($job->fresh())['actualHours']);
    }

    public function test_job_detail_reports_whether_the_viewer_can_see_time_costs(): void
    {
        $job = $this->makeJob();
        $this->logAndApprove($job, hours: 4, billable: true);

        $this->actingAs($this->manager)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobShow')
                ->where('canViewTimeCosts', true));

        $this->actingAs($this->employee)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewTimeCosts', false));
    }

    private function logAndApprove(Job $job, float $hours, bool $billable): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-10',
            'hours' => $hours,
            'billable' => $billable,
        ]);
        $entry = TimeEntry::latest('id')->first();
        $this->actingAs($this->employee)->post("/time-tracking/entries/{$entry->id}/submit");
        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/approve");

        return $entry->refresh();
    }

    private function logAndSubmitOnly(Job $job, float $hours): TimeEntry
    {
        $this->actingAs($this->employee)->post('/time-tracking/entries', [
            'job_id' => $job->id,
            'date' => '2026-08-11',
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

    private function makeTask(Job $job, float $estimatedHours = 8): JobTask
    {
        $schedule = JobSchedule::create([
            'job_id' => $job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        return JobTask::create([
            'job_schedule_id' => $schedule->id,
            'job_id' => $job->id,
            'title' => 'Site survey',
            'estimated_hours' => $estimatedHours,
        ]);
    }
}
