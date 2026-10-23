<?php

namespace Tests\Feature\Api;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile Estimates list — `Api\V1\EstimateController::index`, mirroring
 * `EstimateController::index`'s (web) `ownedBy()` scope: only the
 * signed-in manager's own estimates, addenda excluded, paginated.
 */
class MobileEstimatesTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function makeEstimate(User $owner, array $attributes = []): Estimate
    {
        return Estimate::create([
            'user_id' => $owner->id,
            'number' => 'EST-'.fake()->unique()->numberBetween(1000, 9999),
            'client' => 'Riverside Properties LLC',
            'project' => 'Riverside Office Renovation',
            'issued_on' => now(),
            'amount' => 1000,
            'status' => 'draft',
            'kind' => Estimate::KIND_STANDALONE,
            ...$attributes,
        ]);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/estimates')->assertUnauthorized();
    }

    public function test_a_manager_sees_only_their_own_estimates(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $otherManager = User::factory()->create(['role' => 'Project Manager']);

        $mine = $this->makeEstimate($manager, ['number' => 'EST-0001']);
        $this->makeEstimate($otherManager, ['number' => 'EST-0002']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates')
            ->assertOk();

        $ids = collect($response->json('data.estimates'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_response_shape_matches_the_mobile_contract(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $this->makeEstimate($manager, [
            'number' => 'EST-1234',
            'client' => 'Apex Builders',
            'project' => 'Apex HQ Fit-Out',
            'amount' => 4321.50,
            'status' => 'approved',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'estimates' => [
                        '*' => ['id', 'number', 'client', 'project', 'issuedOn', 'amount', 'status'],
                    ],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);

        $estimate = $response->json('data.estimates.0');
        $this->assertSame('EST-1234', $estimate['number']);
        $this->assertSame('Apex Builders', $estimate['client']);
        $this->assertSame('Apex HQ Fit-Out', $estimate['project']);
        $this->assertSame(4321.5, $estimate['amount']);
        $this->assertSame('approved', $estimate['status']);
        // Never a cost/margin breakdown — same reasoning JobController gives
        // for never sending a job's budget on mobile.
        $this->assertArrayNotHasKey('materialTotal', $estimate);
        $this->assertArrayNotHasKey('markupTotal', $estimate);
    }

    public function test_addenda_are_excluded_exactly_as_the_web_list_excludes_them(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $standalone = $this->makeEstimate($manager, ['number' => 'EST-0001']);
        $this->makeEstimate($manager, [
            'number' => 'EST-0002',
            'kind' => Estimate::KIND_ADDENDUM,
            'parent_estimate_id' => $standalone->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates')
            ->assertOk();

        $ids = collect($response->json('data.estimates'))->pluck('id');
        $this->assertSame([$standalone->id], $ids->all());
    }

    public function test_pagination_meta_and_per_page_are_honoured_and_capped(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        for ($i = 0; $i < 7; $i++) {
            $this->makeEstimate($manager, ['number' => "EST-100{$i}"]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates?per_page=3')
            ->assertOk();

        $this->assertCount(3, $response->json('data.estimates'));
        $this->assertSame([
            'currentPage' => 1,
            'lastPage' => 3,
            'perPage' => 3,
            'total' => 7,
        ], $response->json('data.meta'));

        // Capped at 50 server-side regardless of what the client asks for —
        // same rule the mobile Jobs endpoint enforces.
        $capped = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates?per_page=500')
            ->assertOk();
        $this->assertSame(50, $capped->json('data.meta.perPage'));
    }

    public function test_newest_issued_first_by_default(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $older = $this->makeEstimate($manager, ['number' => 'EST-0001', 'issued_on' => now()->subDays(5)]);
        $newer = $this->makeEstimate($manager, ['number' => 'EST-0002', 'issued_on' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/estimates')
            ->assertOk();

        $ids = collect($response->json('data.estimates'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    public function test_a_journeyman_is_refused_estimates_by_the_roles_matrix(): void
    {
        // Estimates sit behind the same Roles & Permissions matrix as on web: a journeyman
        // starts without `estimates.view`.
        $technician = User::factory()->create([
            'role' => 'Journeyman',
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson('/api/v1/estimates')
            ->assertForbidden();
    }

    // --- update() / item CRUD ------------------------------------------------

    public function test_a_manager_can_update_estimate_header_fields_and_totals_recalculate(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager, ['markup_pct' => 10, 'tax_pct' => 5]);
        $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Duplex Receptacle',
            'unit' => 'ea',
            'quantity' => 10,
            'unit_cost' => 5,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/estimates/{$estimate->id}", [
                'status' => 'approved',
                'issued_on' => now()->toDateString(),
                'markup_pct' => 20,
                'tax_pct' => 10,
                'notes' => 'Reviewed and approved',
            ])
            ->assertOk();

        $fresh = $estimate->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertEquals(20, $fresh->markup_pct);
        $this->assertEquals(10, $fresh->tax_pct);
        $this->assertSame('Reviewed and approved', $fresh->notes);

        // Subtotal 50, markup 20% -> 10, (50+10) tax 10% -> 6, grand 66.
        $this->assertEquals(50, $fresh->subtotal);
        $this->assertEquals(10, $fresh->markup_total);
        $this->assertEquals(6, $fresh->tax_total);
        $this->assertEquals(66, $fresh->grand_total);
        $this->assertEquals(66, $fresh->amount);
        unset($response);
    }

    public function test_update_is_forbidden_for_a_non_owner(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->putJson("/api/v1/estimates/{$estimate->id}", [
                'status' => 'approved',
                'issued_on' => now()->toDateString(),
                'markup_pct' => 10,
                'tax_pct' => 5,
            ])
            ->assertForbidden();
    }

    public function test_a_manager_can_add_a_manual_line_and_totals_recalculate(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager, ['markup_pct' => 0, 'tax_pct' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/estimates/{$estimate->id}/items", [
                'category' => EstimateItem::CATEGORY_LABOR,
                'description' => 'Install labor',
                'unit' => 'hr',
                'quantity' => 4,
                'unit_cost' => 75,
            ])
            ->assertCreated();

        $itemId = $response->json('data.id');
        $this->assertDatabaseHas('estimate_items', [
            'id' => $itemId,
            'estimate_id' => $estimate->id,
            'source' => 'manual',
            'total' => 300,
        ]);
        $this->assertEquals(300, $estimate->fresh()->labor_total);
        $this->assertEquals(300, $estimate->fresh()->grand_total);
    }

    public function test_a_manager_can_update_a_line_and_totals_recalculate(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager, ['markup_pct' => 0, 'tax_pct' => 0]);
        $item = $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Duplex Receptacle',
            'unit' => 'ea',
            'quantity' => 10,
            'unit_cost' => 5,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/estimates/{$estimate->id}/items/{$item->id}", [
                'category' => EstimateItem::CATEGORY_MATERIAL,
                'description' => 'Duplex Receptacle (updated)',
                'unit' => 'ea',
                'quantity' => 20,
                'unit_cost' => 5,
            ])
            ->assertOk();

        $this->assertDatabaseHas('estimate_items', [
            'id' => $item->id,
            'description' => 'Duplex Receptacle (updated)',
            'quantity' => 20,
            'total' => 100,
        ]);
        $this->assertEquals(100, $estimate->fresh()->material_total);
    }

    public function test_a_line_belonging_to_another_estimate_404s_on_update(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager, ['number' => 'EST-0001']);
        $otherEstimate = $this->makeEstimate($manager, ['number' => 'EST-0002']);
        $item = $otherEstimate->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL,
            'description' => 'Elsewhere',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 1,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/estimates/{$estimate->id}/items/{$item->id}", [
                'category' => EstimateItem::CATEGORY_MATERIAL,
                'description' => 'Elsewhere',
                'unit' => 'ea',
                'quantity' => 1,
                'unit_cost' => 1,
            ])
            ->assertNotFound();
    }

    public function test_a_manager_can_delete_a_line_and_totals_recalculate(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager, ['markup_pct' => 0, 'tax_pct' => 0]);
        $item = $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_EQUIPMENT,
            'description' => 'Panel',
            'unit' => 'ea',
            'quantity' => 1,
            'unit_cost' => 500,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->deleteJson("/api/v1/estimates/{$estimate->id}/items/{$item->id}")
            ->assertOk();

        $this->assertDatabaseMissing('estimate_items', ['id' => $item->id]);
        $this->assertEquals(0, $estimate->fresh()->grand_total);
    }

    public function test_labor_rate_reprices_every_labor_line_here_and_on_addenda(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager);
        $addendum = $this->makeEstimate($manager, ['kind' => Estimate::KIND_ADDENDUM, 'parent_estimate_id' => $estimate->id, 'addendum_number' => 1]);

        foreach ([$estimate, $addendum] as $target) {
            $target->items()->create([
                'category' => EstimateItem::CATEGORY_LABOR, 'description' => 'Install labor', 'unit' => 'hr',
                'quantity' => 10, 'unit_cost' => 50, 'total' => 500, 'position' => 1,
            ]);
        }

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/estimates/{$estimate->id}/labor-rate", ['labor_rate' => 80])
            ->assertOk()
            ->assertJsonPath('data.laborRate', 80);

        $this->assertEquals(80, $estimate->items()->first()->fresh()->unit_cost);
        $this->assertEquals(80, $addendum->items()->first()->fresh()->unit_cost);
    }

    public function test_show_carries_the_labor_rate_before_job_flag_and_addendum_lines(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $estimate = $this->makeEstimate($manager);
        $addendum = $this->makeEstimate($manager, ['kind' => Estimate::KIND_ADDENDUM, 'parent_estimate_id' => $estimate->id, 'addendum_number' => 1]);
        $estimate->items()->create([
            'category' => EstimateItem::CATEGORY_LABOR, 'description' => 'Install labor', 'unit' => 'hr',
            'quantity' => 4, 'unit_cost' => 65, 'total' => 260, 'position' => 1,
        ]);
        $addendum->items()->create([
            'category' => EstimateItem::CATEGORY_MATERIAL, 'description' => 'Box', 'unit' => 'ea',
            'quantity' => 2, 'unit_cost' => 5, 'total' => 10, 'position' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson("/api/v1/estimates/{$estimate->id}")
            ->assertOk();

        $this->assertTrue($response->json('data.beforeJob'));
        $this->assertEquals(65, $response->json('data.laborRate'));
        $this->assertSame('Box', $response->json('data.addenda.0.lineItems.0.description'));
    }

    public function test_labor_rate_is_forbidden_for_a_non_owner(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        // Someone in a different company (company-less managers share ownership).
        $stranger = User::factory()->create(['role' => 'Project Manager']);
        $company = \App\Models\CompanyProfile::create([
            'user_id' => $stranger->id, 'name' => 'Other Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'other@x.test', 'timezone' => 'America/Chicago',
        ]);
        $stranger->forceFill(['company_id' => $company->id])->save();
        $estimate = $this->makeEstimate($manager);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($stranger->fresh()))
            ->putJson("/api/v1/estimates/{$estimate->id}/labor-rate", ['labor_rate' => 80])
            ->assertForbidden();
    }

    public function test_edit_options_lists_clients_and_projects_and_update_can_move_the_estimate(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $apex = \App\Models\Client::create(['user_id' => $manager->id, 'name' => 'Apex']);
        $harbor = \App\Models\Client::create(['user_id' => $manager->id, 'name' => 'Harbor']);
        $tower = \App\Models\Project::create(['user_id' => $manager->id, 'client_id' => $harbor->id, 'name' => 'Tower', 'client' => 'Harbor', 'status' => 'draft']);
        $estimate = $this->makeEstimate($manager, ['client_id' => $apex->id]);
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($manager)];

        $options = $this->withHeaders($auth)->getJson("/api/v1/estimates/{$estimate->id}/edit-options")->assertOk();
        $this->assertSame($apex->id, $options->json('data.clientId'));
        $this->assertSame(['Apex', 'Harbor'], array_column($options->json('data.clients'), 'name'));
        $this->assertSame('Tower', $options->json('data.projects.0.name'));

        // Moving it to a project rewrites the client and project snapshots from that project.
        $this->withHeaders($auth)->putJson("/api/v1/estimates/{$estimate->id}", [
            'project_id' => $tower->id, 'status' => 'draft', 'issued_on' => '2026-10-01', 'markup_pct' => 10, 'tax_pct' => 5,
        ])->assertOk();

        $fresh = $estimate->fresh();
        $this->assertSame($tower->id, $fresh->project_id);
        $this->assertSame($harbor->id, $fresh->client_id);
        $this->assertSame('Harbor', $fresh->client);
        $this->assertSame('Tower', $fresh->project);

        // Without project_id the client/project are left alone.
        $this->withHeaders($auth)->putJson("/api/v1/estimates/{$estimate->id}", [
            'status' => 'approved', 'issued_on' => '2026-10-01', 'markup_pct' => 10, 'tax_pct' => 5,
        ])->assertOk();
        $this->assertSame($tower->id, $estimate->fresh()->project_id);
    }

    public function test_the_list_filters_and_reports_the_options_for_them(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $this->makeEstimate($manager, ['number' => 'EST-1', 'client' => 'Apex', 'project' => 'Tower', 'status' => 'draft', 'amount' => 500]);
        $this->makeEstimate($manager, ['number' => 'EST-2', 'client' => 'Beta', 'project' => 'Mall', 'status' => 'sent', 'amount' => 900]);
        $h = ['Authorization' => 'Bearer '.$this->tokenFor($manager)];

        $all = $this->getJson('/api/v1/estimates', $h)->assertOk();
        $this->assertEqualsCanonicalizing(['Apex', 'Beta'], $all->json('data.clients'));
        $this->assertEqualsCanonicalizing(['Tower', 'Mall'], $all->json('data.projects'));

        $this->app['auth']->forgetGuards();
        $this->assertSame(['EST-2'], array_column($this->getJson('/api/v1/estimates?status=sent', $h)->json('data.estimates'), 'number'));
        $this->app['auth']->forgetGuards();
        $this->assertSame(['EST-1'], array_column($this->getJson('/api/v1/estimates?client=Apex', $h)->json('data.estimates'), 'number'));
        $this->app['auth']->forgetGuards();
        $this->assertSame(['EST-2', 'EST-1'], array_column($this->getJson('/api/v1/estimates?sort=amount-desc', $h)->json('data.estimates'), 'number'));
    }

    public function test_an_estimate_can_be_raised_deleted_and_restored(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $client = \App\Models\Client::create(['user_id' => $manager->id, 'name' => 'Apex']);
        $project = \App\Models\Project::create(['user_id' => $manager->id, 'client_id' => $client->id, 'client' => 'Apex', 'name' => 'Tower', 'status' => 'completed']);
        $h = ['Authorization' => 'Bearer '.$this->tokenFor($manager)];

        $opts = $this->getJson('/api/v1/estimates/create-options', $h)->assertOk();
        $this->assertSame('Apex', $opts->json('data.clients.0.name'));
        $this->assertSame($project->id, $opts->json('data.projects.0.id'));

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/estimates', ['issued_on' => now()->toDateString(), 'amount' => 1200, 'status' => 'draft'], $h)
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $made = $this->postJson('/api/v1/estimates', [
            'issued_on' => now()->toDateString(), 'amount' => 1200, 'status' => 'draft', 'project_id' => $project->id, 'client_id' => $client->id,
        ], $h)->assertCreated();
        $this->assertSame('Apex', $made->json('data.client'));
        $this->assertSame('Tower', $made->json('data.project'));
        $id = $made->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/estimates', ['status' => 'draft'], $h)->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->deleteJson("/api/v1/estimates/{$id}", [], $h)->assertOk();
        $this->assertSoftDeleted('estimates', ['id' => $id]);

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/estimates/{$id}/restore", [], $h)->assertOk();
        $this->assertNotSoftDeleted('estimates', ['id' => $id]);
    }
}
