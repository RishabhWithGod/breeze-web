<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Analytics dashboard must cost the same handful of queries whether the
 * company has three jobs or thirty — the per-job N+1 it used to have is what
 * made it slow down as a company grew.
 */
class AnalyticsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
    }

    /** @return array<string, int> */
    public static function tabs(): array
    {
        return ['overview' => ['overview'], 'profitability' => ['profitability'], 'labor' => ['labor'], 'billing' => ['billing'], 'alerts' => ['alerts']];
    }

    /** @dataProvider tabs */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabs')]
    public function test_query_count_does_not_grow_with_the_number_of_jobs(string $tab): void
    {
        $this->seedJobs(3);
        $few = $this->queriesFor($tab);

        $this->seedJobs(12);
        $many = $this->queriesFor($tab);

        $this->assertLessThanOrEqual($few + 2, $many, "tab '{$tab}': {$few} queries for 3 jobs but {$many} for 15");
    }

    protected function queriesFor(string $tab): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->actingAs($this->manager)->get("/job-costing?tab={$tab}&date_from=".now()->subDays(20)->toDateString())->assertOk();

        return $count;
    }

    protected function seedJobs(int $n): void
    {
        $client = Client::first() ?? Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside']);
        $foreman = Foreman::first() ?? Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        for ($i = 0; $i < $n; $i++) {
            $project = Project::create(['user_id' => $this->manager->id, 'client_id' => $client->id, 'name' => "P{$i}", 'client' => $client->name, 'status' => 'in-progress']);
            $job = Job::create(['user_id' => $this->manager->id, 'project_id' => $project->id, 'foreman_id' => $foreman->id, 'name' => "J{$i}", 'client' => $client->name, 'status' => 'in-progress']);

            $worker = User::factory()->create(['role' => 'Electrician']);
            $entry = TimeEntry::create([
                'job_id' => $job->id, 'user_id' => $worker->id, 'date' => now()->subDays(2)->toDateString(),
                'hours' => 6, 'regular_hours' => 6, 'overtime_hours' => 0, 'billable' => true,
                'source' => 'manual', 'status' => TimeEntry::STATUS_APPROVED, 'labor_cost' => 0,
            ]);
            $entry->recordInitialStatus();

            JobCostEntry::create(['job_id' => $job->id, 'category' => 'material', 'description' => 'Wire', 'amount' => 50, 'incurred_on' => now()->subDay()->toDateString(), 'recorded_by' => $this->manager->id]);
            Invoice::create([
                'invoice_number' => Invoice::nextNumber($this->manager), 'job_id' => $job->id, 'client' => $job->client,
                'invoice_date' => now()->toDateString(), 'due_date' => now()->subDays(5)->toDateString(),
                'subtotal' => 400, 'tax_total' => 0, 'total' => 400, 'paid_amount' => 100,
                'status' => Invoice::STATUS_SENT, 'sent_at' => now(),
            ]);
        }
    }
}
