<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\User;
use App\Services\Dashboard\JobPerformanceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dashboard's "On-Time Job Completion" chart: a rolling 12-month,
 * real-data rate — not the fixed Jan–Dec numbers it used to show forever.
 */
class JobPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(string $status, ?string $endDate): Job
    {
        return Job::create([
            'name' => 'Test job '.uniqid(),
            'client' => 'Acme Corp',
            'location' => 'Fairview, CA',
            'job_type' => 'commercial',
            'status' => $status,
            'end_date' => $endDate,
        ]);
    }

    public function test_a_job_completed_by_its_deadline_counts_as_on_time(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $job = $this->makeJob('in-progress', '2026-08-25');
        $job->changeStatus('completed');

        $series = app(JobPerformanceCalculator::class)->series();
        $thisMonth = collect($series)->firstWhere('month', 'Aug 2026');

        $this->assertSame(1, $thisMonth['count']);
        $this->assertSame(100, $thisMonth['value']);

        Carbon::setTestNow();
    }

    public function test_a_job_completed_after_its_deadline_counts_as_late(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $job = $this->makeJob('in-progress', '2026-08-01');
        $job->changeStatus('completed');

        $series = app(JobPerformanceCalculator::class)->series();
        $thisMonth = collect($series)->firstWhere('month', 'Aug 2026');

        $this->assertSame(1, $thisMonth['count']);
        $this->assertSame(0, $thisMonth['value']);

        Carbon::setTestNow();
    }

    public function test_a_job_with_no_deadline_cannot_be_late(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $job = $this->makeJob('in-progress', null);
        $job->changeStatus('completed');

        $series = app(JobPerformanceCalculator::class)->series();
        $thisMonth = collect($series)->firstWhere('month', 'Aug 2026');

        $this->assertSame(100, $thisMonth['value']);

        Carbon::setTestNow();
    }

    public function test_a_month_with_no_completions_reports_no_rate(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $series = app(JobPerformanceCalculator::class)->series();
        $thisMonth = collect($series)->firstWhere('month', 'Aug 2026');

        $this->assertSame(0, $thisMonth['count']);
        $this->assertNull($thisMonth['value']);

        Carbon::setTestNow();
    }

    public function test_completing_a_real_job_changes_the_dashboard_chart(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');
        $user = User::factory()->create();

        $before = $this->actingAs($user)->get('/home');
        $before->assertInertia(fn (Assert $page) => $page
            ->where('performance.11.month', 'Aug 2026')
            ->where('performance.11.value', null));

        $job = $this->makeJob('in-progress', '2026-08-25');
        $job->changeStatus('completed');

        $after = $this->actingAs($user)->get('/home');
        $after->assertInertia(fn (Assert $page) => $page
            ->where('performance.11.month', 'Aug 2026')
            ->where('performance.11.value', 100)
            ->where('performance.11.count', 1));

        Carbon::setTestNow();
    }

    public function test_a_job_that_bounces_out_of_completed_and_back_only_counts_its_latest_completion(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $job = $this->makeJob('in-progress', '2026-08-01');
        $job->changeStatus('completed'); // late
        $job->changeStatus('in-progress');
        $job->changeStatus('completed'); // still late, but only counted once

        $series = app(JobPerformanceCalculator::class)->series();
        $thisMonth = collect($series)->firstWhere('month', 'Aug 2026');

        $this->assertSame(1, $thisMonth['count']);

        Carbon::setTestNow();
    }
}
