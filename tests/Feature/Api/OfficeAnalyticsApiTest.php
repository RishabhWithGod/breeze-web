<?php

namespace Tests\Feature\Api;

use App\Models\AnalyticsSetting;
use App\Models\Client;
use App\Models\Foreman;
use App\Models\Job;
use App\Models\JobCostEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Analytics and Job Costing over the mobile API: the web's data, the same rules. */
class OfficeAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Job $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $this->manager->id, 'name' => 'Riverside']);
        $project = Project::create(['user_id' => $this->manager->id, 'client_id' => $client->id, 'name' => 'P', 'client' => $client->name, 'status' => 'in-progress']);
        $this->job = Job::create([
            'user_id' => $this->manager->id, 'project_id' => $project->id,
            'foreman_id' => Foreman::create(['name' => 'Dana', 'initials' => 'D'])->id,
            'name' => 'Riverside', 'client' => $client->name, 'status' => 'in-progress',
        ]);
    }

    private function headers(User $user): array
    {
        // The guard remembers the first token it saw in a test; each request here is a different person.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_the_dashboard_comes_over_the_api_with_the_same_shape_as_the_web(): void
    {
        $this->getJson('/api/v1/office/analytics?tab=overview', $this->headers($this->manager))
            ->assertOk()
            ->assertJsonPath('data.tab', 'overview')
            ->assertJsonPath('data.canViewCosts', true)
            ->assertJsonPath('data.jobCount', 1)
            ->assertJsonStructure(['data' => ['filters', 'laborTotals', 'profitLoss', 'billing', 'scope', 'alerts', 'trend', 'costBreakdown', 'settings', 'can', 'clients', 'jobs', 'teamMembers']]);
    }

    public function test_the_phone_and_the_web_get_exactly_the_same_numbers(): void
    {
        $this->job->update(['status' => 'in-progress']);

        foreach (['overview', 'profitability', 'labor', 'materials', 'billing', 'alerts'] as $tab) {
            $api = $this->getJson("/api/v1/office/analytics?tab={$tab}", $this->headers($this->manager))->assertOk()->json('data');

            $this->actingAs($this->manager, 'web');
            $web = $this->get("/job-costing?tab={$tab}")->assertOk()->viewData('page')['props'];

            foreach (['tab', 'canViewCosts', 'laborTotals', 'materialTotals', 'profitLoss', 'billing', 'scope', 'overBudgetCount', 'jobCount', 'costBreakdown', 'selectedCost', 'jobsByStatus', 'trend', 'alerts', 'labor', 'billingReport', 'jobRows', 'settings'] as $key) {
                $this->assertEquals($web[$key] ?? null, $api[$key] ?? null, "tab {$tab}: {$key} differs between web and phone");
            }
        }
    }

    public function test_each_tab_loads_only_its_own_data(): void
    {
        $this->getJson('/api/v1/office/analytics?tab=billing', $this->headers($this->manager))
            ->assertOk()->assertJsonPath('data.labor', null)->assertJsonStructure(['data' => ['billingReport' => ['aging']]]);
        $this->getJson('/api/v1/office/analytics?tab=labor', $this->headers($this->manager))
            ->assertOk()->assertJsonStructure(['data' => ['labor' => ['people', 'roles', 'totals']]]);
        $this->getJson('/api/v1/office/analytics?tab=nope', $this->headers($this->manager))->assertStatus(422);
    }

    public function test_a_role_without_cost_access_gets_no_dollar_figures(): void
    {
        $this->getJson('/api/v1/office/analytics', $this->headers(User::factory()->create(['role' => 'Electrician'])))
            ->assertOk()->assertJsonPath('data.canViewCosts', false)->assertJsonPath('data.profitLoss.revenue', null);
    }

    public function test_a_manager_changes_the_thresholds_and_others_cannot(): void
    {
        $payload = ['low_margin_pct' => 22, 'hours_warning_pct' => 85, 'stale_timer_hours' => 9, 'overdue_grace_days' => 2];

        $this->putJson('/api/v1/office/analytics/settings', $payload, $this->headers(User::factory()->create(['role' => 'Electrician'])))->assertForbidden();
        $this->putJson('/api/v1/office/analytics/settings', $payload, $this->headers($this->manager))->assertOk()->assertJsonPath('data.low_margin_pct', 22);
        $this->assertSame(2, AnalyticsSetting::current()->overdue_grace_days);
    }

    public function test_a_jobs_costing_detail_and_cost_entries_work_over_the_api(): void
    {
        $h = $this->headers($this->manager);

        $this->getJson("/api/v1/office/jobs/{$this->job->id}/costing", $h)
            ->assertOk()->assertJsonPath('data.job.id', $this->job->id)->assertJsonStructure(['data' => ['summary', 'laborRows', 'costEntries', 'scope' => ['version', 'history']]]);

        $created = $this->postJson("/api/v1/office/jobs/{$this->job->id}/costing/entries", [
            'category' => 'material', 'description' => 'Wire', 'amount' => 120, 'incurred_on' => now()->toDateString(),
        ], $h)->assertCreated();
        $created->assertJsonPath('data.summary.actualMaterialCost', 120)->assertJsonPath('data.scope.version', 1);

        $entry = JobCostEntry::sole();
        $this->deleteJson("/api/v1/office/jobs/{$this->job->id}/costing/entries/{$entry->id}", [], $h)
            ->assertOk()->assertJsonPath('data.summary.actualMaterialCost', 0);

        $this->postJson("/api/v1/office/jobs/{$this->job->id}/costing/entries", ['category' => 'material'], $this->headers(User::factory()->create(['role' => 'Electrician'])))->assertForbidden();
    }

    public function test_the_report_can_be_exported_by_a_manager_only(): void
    {
        $this->get('/api/v1/office/analytics/export/csv', $this->headers($this->manager))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/api/v1/office/analytics/export/csv', $this->headers(User::factory()->create(['role' => 'Electrician'])))->assertForbidden();
    }
}
