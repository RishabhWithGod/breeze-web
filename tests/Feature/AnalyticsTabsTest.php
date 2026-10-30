<?php

namespace Tests\Feature;

use App\Models\AnalyticsSetting;
use App\Models\Client;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The Analytics tabs, their filters, the period comparison and the alert thresholds. */
class AnalyticsTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->worker = User::factory()->create(['role' => 'Electrician']);
    }

    public function test_only_the_active_tabs_data_is_loaded(): void
    {
        $this->makeJob();

        $this->actingAs($this->manager)->get('/job-costing?tab=labor')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'labor')
                ->has('labor.people')
                ->where('trend', null)
                ->where('billingReport', null));

        $this->actingAs($this->manager)->get('/job-costing?tab=billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('labor', null)
                ->has('trend')
                ->has('billingReport.aging'));
    }

    public function test_an_unknown_tab_is_rejected(): void
    {
        $this->actingAs($this->manager)->get('/job-costing?tab=nope')->assertSessionHasErrors('tab');
    }

    public function test_the_team_member_filter_narrows_hours_not_just_the_job_list(): void
    {
        $job = $this->makeJob();
        $other = User::factory()->create(['role' => 'Electrician']);
        $member = TeamMember::create(['user_id' => $this->worker->id, 'name' => 'Sam', 'initials' => 'S', 'role' => 'Journeyman']);
        $this->entry($job, $this->worker, 5, $member->id);
        $this->entry($job, $other, 7);

        $this->actingAs($this->manager)->get('/job-costing?team_member='.$member->id)
            ->assertInertia(fn (Assert $page) => $page->where('laborTotals.actualHours', 5));
    }

    public function test_the_previous_period_comparison_covers_the_window_just_before(): void
    {
        $job = $this->makeJob();
        $this->entry($job, $this->worker, 4, null, now()->subDays(3));
        $this->entry($job, $this->worker, 10, null, now()->subDays(12));

        $from = now()->subDays(6)->toDateString();
        $to = now()->toDateString();

        $this->actingAs($this->manager)->get("/job-costing?date_from={$from}&date_to={$to}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('laborTotals.actualHours', 4)
                ->where('comparison.laborHours', 10));
    }

    public function test_there_is_no_comparison_without_a_date_range(): void
    {
        $this->makeJob();

        $this->actingAs($this->manager)->get('/job-costing')
            ->assertInertia(fn (Assert $page) => $page->where('comparison', null));
    }

    public function test_billing_ages_unpaid_invoices_into_buckets(): void
    {
        $job = $this->makeJob();
        $this->invoice($job, 1000, 0, now()->addDays(5));
        $this->invoice($job, 500, 100, now()->subDays(10));
        $this->invoice($job, 300, 0, now()->subDays(75));

        $this->actingAs($this->manager)->get('/job-costing?tab=billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('billingReport.aging.current', 1000)
                ->where('billingReport.aging.d1_30', 400)
                ->where('billingReport.aging.d61_plus', 300)
                ->where('billingReport.overdueCount', 2));
    }

    public function test_a_non_manager_gets_no_billing_report(): void
    {
        $this->makeJob();

        $this->actingAs($this->worker)->get('/job-costing?tab=billing')
            ->assertInertia(fn (Assert $page) => $page->where('billingReport', null));
    }

    public function test_the_labor_tab_reports_overtime_by_person_and_role(): void
    {
        $job = $this->makeJob();
        $entry = $this->entry($job, $this->worker, 10);
        $entry->update(['regular_hours' => 8, 'overtime_hours' => 2]);

        $this->actingAs($this->manager)->get('/job-costing?tab=labor')
            ->assertInertia(fn (Assert $page) => $page
                ->where('labor.totals.overtimeHours', 2)
                ->where('labor.totals.overtimePct', 20)
                ->where('labor.people.0.hours', 10));
    }

    public function test_alerts_flag_low_margin_nearing_hours_and_a_stale_timer(): void
    {
        $job = $this->makeJob();
        $this->task($job, 10);
        $this->entry($job, $this->worker, 9.5);
        $this->invoice($job, 100, 0, now()->addDays(5));

        $running = $this->entry($job, $this->worker, 0, null, now()->subDays(2));
        $running->update(['start_time' => '08:00:00', 'end_time' => null]);

        $this->actingAs($this->manager)->get('/job-costing?tab=alerts')
            ->assertInertia(fn (Assert $page) => $page
                ->where('alerts', fn ($alerts) => collect($alerts)->pluck('type')->contains('hours_warning')
                    && collect($alerts)->pluck('type')->contains('missing_checkout')));
    }

    public function test_a_manager_can_change_the_thresholds_and_others_cannot(): void
    {
        $payload = ['low_margin_pct' => 25, 'hours_warning_pct' => 80, 'stale_timer_hours' => 10, 'overdue_grace_days' => 3];

        $this->actingAs($this->worker)->put('/job-costing/settings', $payload)->assertForbidden();

        $this->actingAs($this->manager)->put('/job-costing/settings', $payload)->assertRedirect();
        $this->assertSame(25.0, AnalyticsSetting::current()->low_margin_pct);
        $this->assertSame(3, AnalyticsSetting::current()->overdue_grace_days);
    }

    public function test_thresholds_are_validated(): void
    {
        $this->actingAs($this->manager)
            ->put('/job-costing/settings', ['low_margin_pct' => 200, 'hours_warning_pct' => 0, 'stale_timer_hours' => 0, 'overdue_grace_days' => -1])
            ->assertSessionHasErrors(['low_margin_pct', 'hours_warning_pct', 'stale_timer_hours', 'overdue_grace_days']);
    }

    private function makeJob(): Job
    {
        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside Properties LLC']);
        $project = Project::create([
            'user_id' => $this->manager->id, 'client_id' => $client->id,
            'name' => 'Riverside', 'client' => $client->name, 'status' => 'in-progress',
        ]);

        return Job::create([
            'user_id' => $this->manager->id,
            'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside', 'client' => $client->name, 'status' => 'in-progress',
        ]);
    }

    private function task(Job $job, float $hours): void
    {
        $schedule = JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        JobTask::create([
            'job_schedule_id' => $schedule->id, 'job_id' => $job->id, 'title' => 'Rough-in',
            'status' => 'in-progress', 'priority' => 'medium', 'category' => 'rough-in',
            'estimated_hours' => $hours, 'position' => 1,
        ]);
    }

    private function entry(Job $job, User $user, float $hours, ?int $memberId = null, $date = null): TimeEntry
    {
        $entry = TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'team_member_id' => $memberId,
            'date' => ($date ?? now())->toDateString(),
            'hours' => $hours, 'regular_hours' => $hours, 'overtime_hours' => 0,
            'billable' => true, 'source' => 'manual', 'status' => TimeEntry::STATUS_APPROVED, 'labor_cost' => 0,
        ]);
        $entry->recordInitialStatus();

        return $entry;
    }

    private function invoice(Job $job, float $total, float $paid, $due): Invoice
    {
        return Invoice::create([
            'invoice_number' => Invoice::nextNumber($this->manager),
            'job_id' => $job->id, 'client' => $job->client,
            'invoice_date' => now()->toDateString(), 'due_date' => $due->toDateString(),
            'subtotal' => $total, 'tax_total' => 0, 'total' => $total, 'paid_amount' => $paid,
            'status' => Invoice::STATUS_SENT, 'sent_at' => now(),
        ]);
    }
}
