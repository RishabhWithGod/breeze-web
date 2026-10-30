<?php

namespace Tests\Feature;

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
use App\Models\ProjectRateImport;
use App\Models\ProjectRateItem;
use App\Models\ProjectRateLine;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Estimating\WorkbookReader;
use App\Services\JobCosting\JobCostSummary;
use App\Services\Takeoff\EstimateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `forMany()` and `laborRatesFor()` exist only to avoid per-job queries — they
 * must hand back exactly what the one-at-a-time versions do, across every
 * input that changes a figure.
 */
class JobCostSummaryBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_for_many_matches_for_on_a_varied_set_of_jobs(): void
    {
        $worker = User::factory()->create(['role' => 'Electrician']);
        $member = TeamMember::create(['user_id' => $worker->id, 'name' => 'Sam', 'initials' => 'S', 'role' => 'Journeyman']);

        // A bare job, a priced job with everything attached, a client-rate job, a rate-book job.
        $bare = $this->job('Bare');
        $full = $this->job('Full');
        $overridden = $this->job('Override', clientRate: 95);
        $booked = $this->job('Booked');

        $schedule = JobSchedule::create(['job_id' => $full->id, 'working_days' => [1, 2, 3, 4, 5]]);
        JobTask::create(['job_schedule_id' => $schedule->id, 'job_id' => $full->id, 'title' => 'Rough-in', 'status' => 'in-progress', 'priority' => 'medium', 'category' => 'rough-in', 'estimated_hours' => 40, 'position' => 1]);

        $estimate = Estimate::create([
            'job_id' => $full->id, 'number' => 'EST-1', 'client' => 'Riverside', 'project' => 'P', 'issued_on' => now()->toDateString(),
            'amount' => 9000, 'status' => 'approved', 'material_total' => 2000, 'equipment_total' => 500, 'labor_total' => 3000, 'subtotal' => 5500, 'grand_total' => 9000,
        ]);
        EstimateItem::create(['estimate_id' => $estimate->id, 'category' => 'labor', 'description' => 'Install', 'unit' => 'hr', 'quantity' => 32, 'unit_cost' => 90, 'total' => 2880, 'source' => 'manual']);

        foreach ([$full, $overridden, $booked] as $job) {
            $this->time($job, $worker, 6, $member->id, now()->subDays(3));
            $this->time($job, $worker, 4, null, now()->subDays(40));
        }
        $full->journeymanHours()->create(['user_id' => $worker->id, 'hours' => 11, 'updated_by' => $this->manager->id]);

        JobCostEntry::create(['job_id' => $full->id, 'category' => 'material', 'description' => 'Wire', 'amount' => 120, 'incurred_on' => now()->subDays(2)->toDateString(), 'recorded_by' => $this->manager->id]);
        JobCostEntry::create(['job_id' => $full->id, 'category' => 'other', 'description' => 'Permit', 'amount' => 75, 'incurred_on' => now()->subDays(60)->toDateString(), 'recorded_by' => $this->manager->id]);
        Invoice::create(['invoice_number' => Invoice::nextNumber($this->manager), 'job_id' => $full->id, 'client' => 'Riverside', 'invoice_date' => now()->toDateString(), 'subtotal' => 700, 'tax_total' => 0, 'total' => 700, 'paid_amount' => 200, 'status' => Invoice::STATUS_SENT, 'sent_at' => now()]);
        Invoice::create(['invoice_number' => Invoice::nextNumber($this->manager), 'job_id' => $full->id, 'client' => 'Riverside', 'invoice_date' => now()->toDateString(), 'subtotal' => 50, 'tax_total' => 0, 'total' => 50, 'paid_amount' => 0, 'status' => Invoice::STATUS_DRAFT]);

        $this->rateBook($booked->project_id, 120);

        $jobs = Job::query()->orderBy('id')->get();
        $summary = app(JobCostSummary::class);

        $windows = [
            [null, null, null],
            [now()->subDays(10)->toDateString(), now()->toDateString(), null],
            [null, null, $member->id],
            [now()->subDays(10)->toDateString(), null, $member->id],
        ];

        foreach ($windows as [$from, $to, $memberId]) {
            $batched = app(JobCostSummary::class)->forMany($jobs->fresh(), $from, $to, $memberId)->values()->all();
            $single = Job::query()->orderBy('id')->get()->map(fn (Job $j) => $summary->for($j, $from, $to, $memberId))->values()->all();

            $this->assertEquals($single, $batched, 'window '.json_encode([$from, $to, $memberId]));
        }

        $this->assertCount(4, $jobs);
        $this->assertNotEmpty($bare);
    }

    public function test_batched_labor_rates_match_the_one_at_a_time_rate(): void
    {
        $override = $this->job('A', clientRate: 77.5)->project;
        $booked = $this->job('B')->project;
        $this->rateBook($booked->id, 130);
        $plain = $this->job('C')->project;

        $builder = app(EstimateBuilder::class);
        $batch = $builder->laborRatesFor([$override->fresh('clientRecord'), $booked->fresh('clientRecord'), $plain->fresh('clientRecord')]);

        foreach ([$override, $booked, $plain] as $project) {
            $this->assertSame($builder->laborRateFor($project->fresh('clientRecord')), $batch[$project->id]);
        }
        $this->assertSame(77.5, $batch[$override->id]);
        $this->assertSame(130.0, $batch[$booked->id]);
    }

    public function test_for_many_does_not_leak_state_into_a_later_single_call(): void
    {
        $job = $this->job('Leak');
        $summary = app(JobCostSummary::class);
        $summary->forMany(collect([$job]));

        $this->time($job, User::factory()->create(), 5, null, now());

        $this->assertSame(5.0, $summary->for($job->fresh())['actualLaborHours']);
    }

    private function job(string $name, ?float $clientRate = null): Job
    {
        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside '.$name, 'labor_rate' => $clientRate]);
        $project = Project::create(['user_id' => $this->manager->id, 'client_id' => $client->id, 'name' => $name, 'client' => $client->name, 'status' => 'in-progress']);

        return Job::create([
            'user_id' => $this->manager->id, 'project_id' => $project->id,
            'foreman_id' => (Foreman::first() ?? Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']))->id,
            'name' => $name, 'client' => $client->name, 'status' => 'in-progress',
        ]);
    }

    private function time(Job $job, User $user, float $hours, ?int $memberId, $date): void
    {
        $entry = TimeEntry::create([
            'job_id' => $job->id, 'user_id' => $user->id, 'team_member_id' => $memberId, 'date' => $date->toDateString(),
            'hours' => $hours, 'regular_hours' => $hours, 'overtime_hours' => 0, 'billable' => true,
            'source' => 'manual', 'status' => TimeEntry::STATUS_APPROVED, 'labor_cost' => 0,
        ]);
        $entry->recordInitialStatus();
    }

    private function rateBook(int $projectId, float $laborRate): void
    {
        $import = ProjectRateImport::create(['project_id' => $projectId, 'file_name' => 'x.xlsx', 'file_hash' => bin2hex(random_bytes(32)), 'line_count' => 1, 'imported_at' => now()]);
        $description = 'EM2, NEW BATTERY 2/HEAD EM FIXTURE';
        ProjectRateLine::create([
            'project_rate_import_id' => $import->id, 'project_id' => $projectId, 'description' => $description, 'quantity' => 10,
            'unit' => 'EA', 'unit_material_cost' => 60.0, 'manhour_rate' => $laborRate, 'unit_manhours' => 1.25,
            'match_key' => WorkbookReader::keyFor($description),
        ]);
        ProjectRateItem::create([
            'project_id' => $projectId, 'match_key' => WorkbookReader::keyFor($description), 'unit' => 'EA',
            'description' => $description, 'unit_material_cost' => 60.0, 'line_count' => 2, 'imported_at' => now(),
        ]);
    }
}
