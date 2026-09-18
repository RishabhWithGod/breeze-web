<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\JobSchedule;
use App\Models\JobTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\JobCosting\JobCostSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Job Costing: the summary math, real actual-cost recording, the overrun
 * detection alerts are built on, and who is allowed to see what.
 *
 * Every figure here is checked against rows created with explicit, known
 * amounts — the same discipline `InvoiceSummaryTest` and `TimeEntryTest`
 * already hold this app's other financial totals to.
 */
class JobCostingTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $electrician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $this->electrician = User::factory()->create(['role' => 'Electrician']);
    }

    /**
     * Actual labor hours no longer wait on the Time Tracking approval
     * workflow — every entry counts the moment it exists, whatever its
     * status, so a manager sees the real total on Billing straight away
     * instead of chasing approvals first.
     */
    public function test_labor_hours_count_every_status_except_a_locked_entry_superseded_by_its_correction(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 10);

        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);
        $this->makeTimeEntry($job, $this->electrician, 3, TimeEntry::STATUS_SUBMITTED, 300);
        $this->makeTimeEntry($job, $this->electrician, 2, TimeEntry::STATUS_DRAFT, 200);
        $this->makeTimeEntry($job, $this->electrician, 1, TimeEntry::STATUS_REJECTED, 100);
        // Superseded by a correction — would double the same physical hours if counted.
        $this->makeTimeEntry($job, $this->electrician, 4, TimeEntry::STATUS_LOCKED, 400);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertSame(11.0, $summary['actualLaborHours']);
    }

    /**
     * Actual labor cost is the total hours priced at the project's own
     * effective labor rate — never `time_entries.labor_cost`, which is only
     * ever filled in when a rate happened to be on hand at the moment the
     * entry was logged (often blank in real data).
     */
    public function test_labor_cost_is_hours_priced_at_the_projects_effective_labor_rate(): void
    {
        $job = $this->makeJob();
        // No `labor_cost` on the entry at all — the column this used to sum.
        $this->makeTimeEntry($job, $this->electrician, 6, TimeEntry::STATUS_SUBMITTED, 0);

        $summary = app(JobCostSummary::class)->for($job);

        // No client override on this job's project, so the config default rate applies.
        $this->assertSame(6.0, $summary['actualLaborHours']);
        $this->assertSame(6 * (float) config('ai.estimating.labor_rate'), $summary['actualLaborCost']);
    }

    /** A client's own labor rate override prices actual hours too, not just AI-estimated lines. */
    public function test_labor_cost_uses_the_clients_own_rate_override_when_one_is_set(): void
    {
        $job = $this->makeJob(clientLaborRate: 90.0);
        $this->makeTimeEntry($job, $this->electrician, 4, TimeEntry::STATUS_APPROVED, 0);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertSame(360.0, $summary['actualLaborCost']);
    }

    public function test_a_managers_saved_hours_override_a_persons_raw_time_entry_total(): void
    {
        $job = $this->makeJob();
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);

        $this->actingAs($this->manager)
            ->put("/jobs/{$job->id}/journeyman-hours/{$this->electrician->id}", ['hours' => 8])
            ->assertSessionHasNoErrors();

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(8.0, $summary['actualLaborHours']);

        $rows = app(JobCostSummary::class)->journeymanHours($job);
        $row = $rows->firstWhere('userId', $this->electrician->id);
        $this->assertSame(8.0, $row['hours']);
        $this->assertSame(5.0, $row['rawHours']);
        $this->assertTrue($row['isOverridden']);
    }

    /**
     * Billing is normally raised *after* a job is completed — refusing an
     * edit here once the job is done would make the one time this card
     * matters most the one time it couldn't be used.
     */
    public function test_a_completed_jobs_journeyman_hours_can_still_be_edited(): void
    {
        $job = $this->makeJob(['status' => Job::STATUS_COMPLETED]);
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);

        $this->actingAs($this->manager)
            ->put("/jobs/{$job->id}/journeyman-hours/{$this->electrician->id}", ['hours' => 7.5])
            ->assertSessionHasNoErrors();

        $this->assertSame('7.50', $job->journeymanHours()->sole()->hours);
    }

    public function test_journeyman_hours_sum_every_persons_total_with_no_override_needed(): void
    {
        $job = $this->makeJob();
        $journeymanB = User::factory()->create(['role' => 'Journeyman']);
        $journeymanC = User::factory()->create(['role' => 'Journeyman']);

        $this->makeTimeEntry($job, $this->electrician, 8, TimeEntry::STATUS_SUBMITTED, 800);
        $this->makeTimeEntry($job, $journeymanB, 6, TimeEntry::STATUS_DRAFT, 600);
        $this->makeTimeEntry($job, $journeymanC, 5, TimeEntry::STATUS_APPROVED, 500);

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(19.0, $summary['actualLaborHours']);

        $rows = app(JobCostSummary::class)->journeymanHours($job);
        $this->assertSame(3, $rows->count());
        $this->assertSame(19.0, round($rows->sum('hours'), 2));
    }

    public function test_material_cost_comes_from_real_cost_entries_never_invented(): void
    {
        $job = $this->makeJob();

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(0.0, $summary['actualMaterialCost']);

        JobCostEntry::create([
            'job_id' => $job->id,
            'category' => JobCostEntry::CATEGORY_MATERIAL,
            'description' => 'Conduit and boxes',
            'quantity' => 10,
            'unit_cost' => 25,
            'amount' => 250,
            'incurred_on' => now()->toDateString(),
        ]);

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(250.0, $summary['actualMaterialCost']);
    }

    /**
     * There is no purchasing/inventory system in this app, so a material or
     * equipment line's one real, priced total is the estimate the job was
     * actually priced from — the same line items shown on the invoice raised
     * from it. Actual starts there, same as Estimated does, rather than at
     * zero just because nobody separately logged a `job_cost_entries` row.
     */
    public function test_material_and_equipment_actual_cost_reflects_the_jobs_own_estimate(): void
    {
        $job = $this->makeJob();
        $estimate = Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-2001',
            'client' => $job->client,
            'project' => 'Panel upgrade',
            'issued_on' => now()->toDateString(),
            'amount' => 3500,
            'status' => 'approved',
            'material_total' => 2000,
            'equipment_total' => 500,
            'labor_total' => 1000,
            'subtotal' => 3500,
            'grand_total' => 3500,
        ]);

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(2000.0, $summary['estimatedMaterialCost']);
        $this->assertSame(2000.0, $summary['actualMaterialCost']);
        $this->assertSame(500.0, $summary['estimatedEquipmentCost']);
        $this->assertSame(500.0, $summary['actualEquipmentCost']);

        // A real overage on top, still additive rather than replacing the estimate's own total.
        JobCostEntry::create([
            'job_id' => $job->id,
            'category' => JobCostEntry::CATEGORY_MATERIAL,
            'description' => 'Extra conduit run',
            'amount' => 150,
            'incurred_on' => now()->toDateString(),
        ]);

        $summary = app(JobCostSummary::class)->for($job);
        $this->assertSame(2150.0, $summary['actualMaterialCost']);
    }

    public function test_date_range_narrows_actual_figures_but_not_the_estimated_plan(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 20);

        $this->makeTimeEntry($job, $this->electrician, 4, TimeEntry::STATUS_APPROVED, 400, now()->subDays(40));
        $this->makeTimeEntry($job, $this->electrician, 6, TimeEntry::STATUS_APPROVED, 600, now()->subDays(5));

        $windowed = app(JobCostSummary::class)->for($job, now()->subDays(10)->toDateString(), now()->toDateString());
        $allTime = app(JobCostSummary::class)->for($job, null, null);

        $this->assertSame(6.0, $windowed['actualLaborHours']);
        $this->assertSame(10.0, $allTime['actualLaborHours']);
        // The plan itself never changes with the window.
        $this->assertSame(20.0, $windowed['estimatedLaborHours']);
        $this->assertSame(20.0, $allTime['estimatedLaborHours']);
    }

    public function test_revenue_is_the_estimates_contract_value_when_one_exists(): void
    {
        $job = $this->makeJob();
        $estimate = Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-1001',
            'client' => $job->client,
            'project' => 'Panel upgrade',
            'issued_on' => now()->toDateString(),
            'amount' => 5000,
            'status' => 'approved',
            'material_total' => 2000,
            'labor_total' => 3000,
            'subtotal' => 5000,
            'grand_total' => 5000,
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id,
            'category' => 'material',
            'description' => 'Panel',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 2000,
            'total' => 2000,
            'source' => 'manual',
        ]);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertSame(5000.0, $summary['revenue']);
        $this->assertSame(2000.0, $summary['estimatedMaterialCost']);
        $this->assertSame(3000.0, $summary['estimatedLaborCost']);
    }

    public function test_revenue_falls_back_to_billed_invoices_when_there_is_no_estimate(): void
    {
        $job = $this->makeJob();

        Invoice::create([
            'invoice_number' => Invoice::nextNumber($this->manager),
            'job_id' => $job->id,
            'client' => $job->client,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 1000, 'tax_total' => 0, 'total' => 1000, 'paid_amount' => 400,
            'status' => Invoice::STATUS_SENT, 'sent_at' => now(),
        ]);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertSame(1000.0, $summary['revenue']);
        $this->assertSame(1000.0, $summary['billed']);
        $this->assertSame(400.0, $summary['paid']);
        $this->assertSame(600.0, $summary['outstanding']);
    }

    public function test_revenue_falls_back_to_billed_even_when_an_estimate_exists_but_was_never_priced(): void
    {
        // A skeleton estimate — raised but never given line items, so its
        // `grand_total` is a real, stored `0.00`, not a null. Revenue must
        // still reflect the real invoice, not this unpriced placeholder.
        $job = $this->makeJob();
        Estimate::create([
            'job_id' => $job->id,
            'number' => 'EST-2001',
            'client' => $job->client,
            'project' => 'Unpriced draft',
            'issued_on' => now()->toDateString(),
            'amount' => 0,
            'status' => 'draft',
        ]);
        Invoice::create([
            'invoice_number' => Invoice::nextNumber($this->manager),
            'job_id' => $job->id,
            'client' => $job->client,
            'invoice_date' => now()->toDateString(),
            'subtotal' => 750, 'tax_total' => 0, 'total' => 750, 'paid_amount' => 0,
            'status' => Invoice::STATUS_SENT, 'sent_at' => now(),
        ]);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertSame(750.0, $summary['revenue']);
    }

    public function test_a_job_over_its_labor_hour_budget_is_flagged_as_an_overrun(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 5);
        $this->makeTimeEntry($job, $this->electrician, 8, TimeEntry::STATUS_APPROVED, 800);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertTrue($summary['isOverBudget']);
        $this->assertSame('labor_hours', $summary['overrunReason']);
    }

    public function test_a_job_within_budget_has_no_overrun(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 20);
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);

        $summary = app(JobCostSummary::class)->for($job);

        $this->assertFalse($summary['isOverBudget']);
        $this->assertNull($summary['overrunReason']);
    }

    public function test_the_dashboard_is_open_to_any_signed_in_user(): void
    {
        $this->actingAs($this->electrician)
            ->get('/job-costing')
            ->assertInertia(fn (Assert $page) => $page->component('JobCosting'));
    }

    /**
     * The dashboard is open to view, but dollar figures are not — the
     * backend must null every cost field it sends, not merely rely on the
     * frontend to hide them. Every field checked here is a real key
     * `JobCostSummary::for()` returns.
     */
    public function test_a_non_manager_receives_no_dollar_figures_on_the_dashboard(): void
    {
        $job = $this->makeJob();
        $this->makeTask($job, estimatedHours: 10);
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);
        JobCostEntry::create([
            'job_id' => $job->id, 'category' => 'material', 'description' => 'Panel',
            'amount' => 500, 'incurred_on' => now()->toDateString(), 'recorded_by' => $this->manager->id,
        ]);

        $this->actingAs($this->electrician)
            ->get('/job-costing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewCosts', false)
                ->where('laborTotals.estimatedCost', null)
                ->where('laborTotals.actualCost', null)
                ->where('materialTotals.estimatedCost', null)
                ->where('materialTotals.actualCost', null)
                ->where('profitLoss.revenue', null)
                ->where('profitLoss.profit', null)
                ->where('profitLoss.marginPct', null)
                ->where('topProfitable.0.estimatedLaborCost', null)
                ->where('topProfitable.0.actualTotalCost', null)
                ->where('topProfitable.0.profit', null)
                ->where('topProfitable.0.revenue', null));
    }

    public function test_a_manager_receives_the_real_dollar_figures_on_the_dashboard(): void
    {
        $job = $this->makeJob();
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);

        // 5 hours at the project's own effective rate (no client override here).
        $expectedCost = 5 * (int) config('ai.estimating.labor_rate');

        $this->actingAs($this->manager)
            ->get('/job-costing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewCosts', true)
                ->where('laborTotals.actualCost', $expectedCost));
    }

    /** The same rule applies to the per-job detail screen, not just the dashboard. */
    public function test_a_non_manager_receives_no_dollar_figures_on_the_job_detail_screen(): void
    {
        $job = $this->makeJob();
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);
        JobCostEntry::create([
            'job_id' => $job->id, 'category' => 'material', 'description' => 'Panel',
            'amount' => 500, 'incurred_on' => now()->toDateString(), 'recorded_by' => $this->manager->id,
        ]);

        $this->actingAs($this->electrician)
            ->get("/jobs/{$job->id}/costing")
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewCosts', false)
                ->where('summary.actualLaborCost', null)
                ->where('summary.revenue', null)
                ->where('summary.profit', null)
                ->where('laborRows.0.laborCost', null)
                ->where('costEntries.0.amount', null));
    }

    /** And to the Job Detail page's own embedded cost widgets. */
    public function test_a_non_manager_receives_no_dollar_figures_on_job_detail(): void
    {
        $job = $this->makeJob();
        $this->makeTimeEntry($job, $this->electrician, 5, TimeEntry::STATUS_APPROVED, 500);

        $this->actingAs($this->electrician)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('canViewTimeCosts', false)
                ->where('jobCosting.actualLaborCost', null)
                ->where('jobCosting.profit', null));
    }

    /** Exporting is still a view of cost data — same gate, not a bypass. */
    public function test_a_non_manager_cannot_export_the_cost_report(): void
    {
        $this->actingAs($this->electrician)
            ->get('/job-costing/export/csv')
            ->assertForbidden();
    }

    public function test_only_a_manager_can_log_an_actual_cost_entry(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->electrician)->post("/jobs/{$job->id}/costing/entries", [
            'category' => 'material',
            'description' => 'Panel',
            'amount' => 500,
            'incurred_on' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame(0, JobCostEntry::count());

        $this->actingAs($this->manager)->post("/jobs/{$job->id}/costing/entries", [
            'category' => 'material',
            'description' => 'Panel',
            'amount' => 500,
            'incurred_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, JobCostEntry::count());
    }

    public function test_approving_time_that_pushes_a_job_over_its_labor_budget_notifies_managers(): void
    {
        $job = $this->makeJob();
        $task = $this->makeTask($job, estimatedHours: 4);

        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'job_task_id' => $task->id,
            'user_id' => $this->electrician->id,
            'date' => now()->toDateString(),
            'start_time' => '08:00:00', 'end_time' => '18:00:00', 'break_minutes' => 0,
            'hours' => 10, 'regular_hours' => 8, 'overtime_hours' => 2,
            'billable' => true, 'source' => 'manual', 'status' => TimeEntry::STATUS_SUBMITTED,
            'labor_cost' => 1000,
        ]);
        $entry->recordInitialStatus();

        $this->actingAs($this->manager)->post("/time-tracking/entries/{$entry->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            AppNotification::where('user_id', $this->manager->id)->where('type', 'job-cost-overrun')->count(),
        );
    }

    public function test_job_detail_carries_the_job_costing_summary(): void
    {
        $job = $this->makeJob();

        $this->actingAs($this->manager)
            ->get("/jobs/{$job->id}")
            ->assertInertia(fn (Assert $page) => $page->has('jobCosting'));
    }

    public function test_the_detail_screen_shows_labor_rows_grouped_by_real_person_not_split(): void
    {
        $job = $this->makeJob();
        $this->makeTimeEntry($job, $this->electrician, 3, TimeEntry::STATUS_APPROVED, 300);
        $this->makeTimeEntry($job, $this->electrician, 2, TimeEntry::STATUS_APPROVED, 200);

        $this->actingAs($this->manager)
            ->get("/jobs/{$job->id}/costing")
            ->assertInertia(fn (Assert $page) => $page
                ->component('JobCostingDetail')
                ->has('laborRows', 1)
                ->where('laborRows.0.hours', 5));
    }

    private function makeJob(array $attributes = [], ?float $clientLaborRate = null): Job
    {
        $client = Client::create([
            'user_id' => $this->manager->id,
            'name' => 'Riverside Properties LLC',
            'labor_rate' => $clientLaborRate,
        ]);
        $project = Project::create([
            'user_id' => $this->manager->id,
            'client_id' => $client->id,
            'name' => 'Riverside Office Renovation',
            'client' => $client->name,
            'status' => 'in-progress',
        ]);

        return Job::create([
            // `TimeEntryPolicy::approve()` requires the acting manager to own
            // the job an entry is on — needed by the approve-triggered
            // overrun-notification test below.
            'user_id' => $this->manager->id,
            'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW'])->id,
            'name' => 'Riverside Office Renovation',
            'client' => 'Riverside Properties LLC',
            'status' => 'in-progress',
            ...$attributes,
        ]);
    }

    private function makeTask(Job $job, float $estimatedHours): JobTask
    {
        $schedule = JobSchedule::create([
            'job_id' => $job->id,
            'working_days' => [1, 2, 3, 4, 5],
        ]);

        return JobTask::create([
            'job_schedule_id' => $schedule->id,
            'job_id' => $job->id,
            'title' => 'Rough-in',
            'status' => 'in-progress',
            'priority' => 'medium',
            'category' => 'rough-in',
            'estimated_hours' => $estimatedHours,
            'position' => 1,
        ]);
    }

    private function makeTimeEntry(Job $job, User $user, float $hours, string $status, float $laborCost, $date = null): TimeEntry
    {
        $entry = TimeEntry::create([
            'job_id' => $job->id,
            'user_id' => $user->id,
            'date' => ($date ?? now())->toDateString(),
            'hours' => $hours,
            'regular_hours' => $hours,
            'overtime_hours' => 0,
            'billable' => true,
            'source' => 'manual',
            'status' => $status,
            'labor_cost' => $laborCost,
        ]);
        $entry->recordInitialStatus();

        return $entry;
    }
}
