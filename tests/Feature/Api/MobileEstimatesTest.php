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

    public function test_a_pending_approval_mobile_technician_sees_an_empty_list_not_an_error(): void
    {
        // Estimates are inherently manager-owned data (ownedBy scope) — a
        // field technician account naturally has none of their own, so this
        // route needs no extra role gate beyond auth:sanctum+account.active
        // (see routes/api.php's comment on the estimates.index route).
        $technician = User::factory()->create([
            'role' => 'Journeyman',
            'status' => User::STATUS_ACTIVE,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->getJson('/api/v1/estimates')
            ->assertOk();

        $this->assertSame([], $response->json('data.estimates'));
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
}
