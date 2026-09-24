<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Foreman;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The new mobile "show"/detail endpoints added so the app's detail screens
 * stop reading `Mock*.byId` (which crashed on any real id from the
 * already-real list screens): Estimate, Client, Project, Team member
 * (Foreman) and Takeoff (Project) show, plus the mobile Billing summary.
 */
class MobileDetailEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function manager(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'Project Manager', ...$attributes]);
    }

    // --- Estimate show -----------------------------------------------------

    public function test_estimate_show_returns_line_items_and_breakdown(): void
    {
        $owner = $this->manager();
        $estimate = Estimate::create([
            'user_id' => $owner->id, 'number' => 'EST-0001', 'client' => 'Apex',
            'project' => 'Apex HQ', 'issued_on' => now(), 'amount' => 500,
            'status' => 'draft', 'kind' => Estimate::KIND_STANDALONE,
            'material_total' => 300, 'labor_total' => 150, 'subtotal' => 450,
            'tax_total' => 50, 'grand_total' => 500,
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'category' => 'material',
            'description' => 'Conduit', 'unit' => 'ft', 'quantity' => 100,
            'unit_cost' => 1.5, 'total' => 150,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/estimates/{$estimate->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'number', 'grandTotal', 'lineItems' => ['*' => ['id', 'description', 'quantity', 'unitCost', 'total']]],
            ]);

        $this->assertSame('EST-0001', $response->json('data.number'));
        $this->assertSame(1, count($response->json('data.lineItems')));
        $this->assertSame('Conduit', $response->json('data.lineItems.0.description'));
    }

    public function test_estimate_show_includes_web_parity_fields_for_the_detail_screen(): void
    {
        $owner = $this->manager();
        $estimate = Estimate::create([
            'user_id' => $owner->id, 'number' => 'EST-0003', 'client' => 'Apex',
            'project' => 'Apex HQ', 'issued_on' => now(), 'amount' => 500,
            'status' => 'draft', 'kind' => Estimate::KIND_STANDALONE,
            'material_total' => 300, 'labor_total' => 150, 'subtotal' => 450,
            'tax_total' => 50, 'grand_total' => 500,
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'category' => 'material',
            'description' => 'Conduit', 'unit' => 'ft', 'quantity' => 100,
            'unit_cost' => 1.5, 'total' => 150, 'source' => 'manual',
            'pricing_source' => 'vendor-rate-list',
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'category' => 'labor',
            'description' => 'Install', 'unit' => 'hr', 'quantity' => 8,
            'unit_cost' => 60, 'total' => 480, 'source' => 'manual',
            'pricing_source' => 'unmatched',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/estimates/{$estimate->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'fromTakeoff', 'createdAt', 'laborHours', 'engineSubtotal',
                    'engineGrandTotal', 'engineLineCount', 'matchedLines', 'unmatchedLines',
                    'drawingName', 'reviewed',
                    'lineItems' => ['*' => ['source', 'pricingSource', 'pricingConfidence']],
                    'drawingData' => ['wireSizes', 'equipment', 'panelSchedules'],
                ],
            ]);

        $this->assertFalse($response->json('data.fromTakeoff'));
        $this->assertTrue($response->json('data.reviewed'));
        $this->assertEqualsWithDelta(8.0, $response->json('data.laborHours'), 0.001);
        $this->assertSame(1, $response->json('data.matchedLines'));
        $this->assertSame(1, $response->json('data.unmatchedLines'));
    }

    public function test_estimate_show_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        $estimate = Estimate::create([
            'user_id' => $owner->id, 'number' => 'EST-0002', 'client' => 'Apex',
            'project' => 'Apex HQ', 'issued_on' => now(), 'amount' => 500,
            'status' => 'draft', 'kind' => Estimate::KIND_STANDALONE,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/estimates/{$estimate->id}")
            ->assertForbidden();
    }

    // --- Client show ---------------------------------------------------------

    public function test_client_show_returns_addresses_and_projects(): void
    {
        $owner = $this->manager();
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders', 'notes' => 'VIP']);
        ClientAddress::create(['client_id' => $client->id, 'label' => 'HQ', 'address' => '123 Main St', 'is_primary' => true]);
        Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Apex Fit-Out', 'client' => 'Apex Builders', 'status' => 'draft']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'notes', 'laborRate', 'addresses' => ['*' => ['id', 'label', 'address']], 'projects' => ['*' => ['id', 'name']]],
            ]);

        $this->assertSame('Apex Builders', $response->json('data.name'));
        $this->assertSame(1, count($response->json('data.addresses')));
        $this->assertSame(1, count($response->json('data.projects')));
    }

    public function test_client_show_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/clients/{$client->id}")
            ->assertForbidden();
    }

    // --- Project show --------------------------------------------------------

    public function test_project_show_returns_addresses_jobs_and_estimates(): void
    {
        $owner = $this->manager();
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        ClientAddress::create(['client_id' => $client->id, 'label' => 'Site A', 'address' => '1 First Ave']);
        $project = Project::create([
            'user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Apex Fit-Out',
            'client' => 'Apex Builders', 'status' => 'draft',
        ]);
        Job::create([
            'user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Apex Job 1',
            'client' => 'Apex Builders', 'status' => 'in-progress',
        ]);
        Estimate::create([
            'user_id' => $owner->id, 'project_id' => $project->id, 'number' => 'EST-0003',
            'client' => 'Apex Builders', 'project' => 'Apex Fit-Out', 'issued_on' => now(),
            'amount' => 200, 'status' => 'draft', 'kind' => Estimate::KIND_STANDALONE,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'clientId',
                    'addresses' => ['*' => ['id', 'label', 'address']],
                    'jobs' => ['*' => ['id', 'name', 'status']],
                    'estimates' => ['*' => ['id', 'number', 'amount']],
                ],
            ]);

        $this->assertSame(1, count($response->json('data.addresses')));
        $this->assertSame(1, count($response->json('data.jobs')));
        $this->assertSame(1, count($response->json('data.estimates')));
    }

    public function test_project_show_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Apex Fit-Out', 'client' => 'Apex', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertForbidden();
    }

    // --- Team member show ------------------------------------------------------

    public function test_team_member_show_returns_jobs_and_zero_breeze_bucks_when_unlinked(): void
    {
        $manager = $this->manager();
        $member = Foreman::create(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => Foreman::ROLE_FOREMAN]);
        Job::create(['user_id' => $manager->id, 'foreman_id' => $member->id, 'name' => 'Job A', 'client' => 'Apex', 'status' => 'in-progress']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson("/api/v1/team/{$member->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'role', 'breezeBucksBalance', 'jobs' => ['*' => ['id', 'name', 'status']]],
            ]);

        $this->assertSame('Sam Rivera', $response->json('data.name'));
        $this->assertSame(0, $response->json('data.breezeBucksBalance'));
        $this->assertSame(1, count($response->json('data.jobs')));
    }

    // --- Takeoff show -----------------------------------------------------------

    public function test_takeoff_show_returns_empty_stages_and_symbols_for_a_draft_project(): void
    {
        $owner = $this->manager();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Draft Takeoff', 'client' => 'Apex', 'status' => 'draft']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'projectName', 'status', 'progress', 'stages', 'symbols']]);

        $this->assertSame([], $response->json('data.stages'));
        $this->assertSame([], $response->json('data.symbols'));
        $this->assertEquals(0.0, $response->json('data.progress'));
    }

    public function test_takeoff_show_is_forbidden_for_a_non_owner(): void
    {
        $owner = $this->manager();
        $other = $this->manager();
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Draft Takeoff', 'client' => 'Apex', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->getJson("/api/v1/takeoffs/{$project->id}")
            ->assertForbidden();
    }

    // --- Billing summary ---------------------------------------------------------

    public function test_billing_summary_matches_the_web_calculator(): void
    {
        $owner = $this->manager();
        Invoice::create([
            'user_id' => $owner->id, 'invoice_number' => 'INV-0001', 'client' => 'Apex Builders',
            'status' => Invoice::STATUS_PAID, 'subtotal' => 100, 'total' => 100, 'paid_amount' => 100,
            'invoice_date' => now()->subDays(10), 'paid_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/billing/summary')
            ->assertOk()
            ->assertJsonStructure(['data' => ['totalOutstanding', 'overdue', 'overdueCount', 'paidThisMonth', 'averageDaysToPay']]);

        $this->assertEquals(100.0, $response->json('data.paidThisMonth'));
    }

    public function test_billing_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/billing/summary')->assertUnauthorized();
    }
}
