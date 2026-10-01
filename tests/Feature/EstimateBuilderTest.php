<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\PriceBookItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Estimate Builder: quantities into priced labor and material lines.
 */
class EstimateBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CompanyProfile, 1: User} */
    private function company(string $name = 'Volt & Co', string $role = 'Project Manager'): array
    {
        $manager = User::factory()->create(['role' => $role]);
        $company = CompanyProfile::create([
            'user_id' => $manager->id, 'name' => $name, 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        // Setup is done, so the dashboard is the dashboard.
        $company->forceFill(['onboarding_finished_at' => now()])->save();
        $manager->forceFill(['company_id' => $company->id])->save();

        return [$company, $manager->fresh()];
    }

    private function project(User $owner): Project
    {
        return Project::create(['user_id' => $owner->id, 'name' => 'Riverside Office Renovation', 'client' => 'Acme Properties', 'status' => 'draft']);
    }

    private function builderEstimate(User $owner): Estimate
    {
        $this->actingAs($owner)->post(route('estimate-builder.store'), ['project_id' => $this->project($owner)->id]);

        return Estimate::where('builder_managed', true)->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $lines, array $settings = []): array
    {
        return ['lines' => $lines, 'settings' => ['tax_pct' => 8.25, 'markup_pct' => 15, 'labor_rate' => 65, 'scope_of_work' => 'Furnish all labor and materials for the electrical scope.', ...$settings]];
    }

    /** @return list<array<string, mixed>> */
    private function twoLines(): array
    {
        return [
            ['description' => '2x4 Wood Stud Wall', 'commodity' => 'Drywall & Framing', 'unit' => 'lf',
                'material_qty' => 120, 'material_unit_price' => 1.85, 'labor_hours' => 18, 'labor_rate' => 75, 'markup_pct' => 15],
            ['description' => '5/8" GWB Installation', 'commodity' => 'Drywall', 'unit' => 'SF',
                'material_qty' => 480, 'material_unit_price' => 0.62, 'labor_hours' => 40, 'labor_rate' => 68, 'markup_pct' => 15],
        ];
    }

    public function test_a_new_estimate_starts_on_a_project_ready_to_build(): void
    {
        [, $pm] = $this->company();
        $project = $this->project($pm);

        $this->actingAs($pm)->post(route('estimate-builder.store'), ['project_id' => $project->id])->assertRedirect();

        $estimate = Estimate::sole();
        $this->assertTrue($estimate->builder_managed);
        $this->assertSame('draft', $estimate->status);
        $this->assertSame('Riverside Office Renovation', $estimate->project);
        $this->assertSame('Acme Properties', $estimate->client);
        $this->assertMatchesRegularExpression('/^EST-\d+$/', $estimate->number);

        $this->actingAs($pm)->get(route('estimate-builder.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('EstimateBuilderHome')
                ->has('estimates', 1)
                ->where('estimates.0.number', $estimate->number)
                ->has('projects', 1));
        $this->actingAs($pm)->post(route('estimate-builder.store'), [])->assertSessionHasErrors('project_id');
    }

    public function test_saving_prices_each_row_and_re_adds_the_estimate(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);

        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Draft saved.');

        $estimate->refresh();
        // Material 222.00 + 297.60; labor 1,350.00 + 2,720.00.
        $this->assertEquals(519.60, $estimate->material_total);
        $this->assertEquals(4070.00, $estimate->labor_total);
        $this->assertEquals(4589.60, $estimate->subtotal);
        // Each row marked up by its own 15%: 235.80 + 452.64.
        $this->assertEquals(688.44, $estimate->markup_total);
        // Tax at 8.25% on the marked-up 5,278.04.
        $this->assertEquals(435.44, $estimate->tax_total);
        $this->assertEquals(5713.48, $estimate->grand_total);
        $this->assertEquals(5713.48, $estimate->amount);

        // The rows became ordinary lines on the estimate: a material and a labor line each.
        $items = EstimateItem::where('estimate_id', $estimate->id)->orderBy('position')->get();
        $this->assertCount(4, $items);
        $this->assertSame(['material', 'labor', 'material', 'labor'], $items->pluck('category')->all());
        $this->assertSame(['LF', 'HR', 'SF', 'HR'], $items->pluck('unit')->all());
        $this->assertTrue($items->every(fn ($item) => $item->builder_line_id !== null && (float) $item->markup_pct === 15.0));
        $this->assertSame('2x4 Wood Stud Wall — labor', $items[1]->description);
        $this->assertNotNull($estimate->commodity_version);
    }

    public function test_the_worksheet_reads_back_what_was_saved(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines(), ['tax_pct' => 7]));

        $this->actingAs($pm)->get(route('estimate-builder.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->component('EstimateBuilder')
                ->where('estimate.taxPct', 7)
                ->where('estimate.markupPct', 15)
                ->where('estimate.locked', false)
                ->has('lines', 2)
                ->where('lines.0.description', '2x4 Wood Stud Wall')
                ->where('lines.0.unit', 'LF')
                ->where('lines.0.materialQty', 120)
                ->where('lines.0.laborRate', 75)
                ->where('lines.1.materialUnitPrice', 0.62));
    }

    public function test_saving_again_edits_in_place_and_drops_rows_taken_off(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()));
        $first = $estimate->builderLines()->first();

        // Change the first row's quantity; leave the second off the sheet.
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload([
            [...$this->twoLines()[0], 'id' => $first->id, 'material_qty' => 200],
        ]))->assertSessionHasNoErrors();

        $estimate->refresh();
        $this->assertSame(1, $estimate->builderLines()->count());
        $this->assertSame($first->id, $estimate->builderLines()->sole()->id);
        // 200 × 1.85 = 370.00 material; labor 1,350.00 — and only that row's two lines remain.
        $this->assertEquals(370.00, $estimate->material_total);
        $this->assertEquals(1350.00, $estimate->labor_total);
        $this->assertSame(2, $estimate->items()->count());
        $this->assertEquals(1720.00 + 258.00 + round(1978 * 0.0825, 2), $estimate->grand_total);
    }

    public function test_a_row_with_nothing_on_one_side_gets_no_line_for_it(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);

        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload([
            ['description' => 'Material only', 'unit' => 'EA', 'material_qty' => 2, 'material_unit_price' => 10, 'markup_pct' => 0],
            ['description' => 'Labor only', 'labor_hours' => 3, 'labor_rate' => 50, 'markup_pct' => 0],
            ['description' => 'Not priced yet', 'markup_pct' => 0],
        ], ['tax_pct' => 0]))->assertSessionHasNoErrors();

        $this->assertSame(3, $estimate->builderLines()->count());
        $this->assertSame(['material', 'labor'], EstimateItem::where('estimate_id', $estimate->id)->orderBy('position')->pluck('category')->all());
        $this->assertEquals(170.00, $estimate->fresh()->grand_total);
    }

    public function test_bad_input_is_refused(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);
        $save = fn (array $lines, array $settings = []) => $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($lines, $settings));

        $save([['description' => '']])->assertSessionHasErrors('lines.0.description');
        $save([['description' => 'X', 'material_qty' => -1]])->assertSessionHasErrors('lines.0.material_qty');
        $save([['description' => 'X', 'labor_hours' => 'lots']])->assertSessionHasErrors('lines.0.labor_hours');
        $save([['description' => 'X', 'markup_pct' => 5000]])->assertSessionHasErrors('lines.0.markup_pct');
        $save([], ['tax_pct' => 101])->assertSessionHasErrors('settings.tax_pct');
        $save([], ['labor_rate' => -5])->assertSessionHasErrors('settings.labor_rate');

        $this->assertSame(0, $estimate->builderLines()->count());
    }

    public function test_a_takeoff_can_be_copied_in_without_touching_it(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);

        $source = Estimate::create([
            'user_id' => $pm->id, 'project_id' => $estimate->project_id, 'number' => 'EST-2001', 'client' => 'Acme', 'project' => 'Takeoff',
            'issued_on' => '2026-01-01', 'amount' => 0, 'status' => 'draft', 'kind' => 'standalone',
        ]);
        $material = $source->items()->create(['category' => 'material', 'description' => 'Duplex outlet', 'unit' => 'EA', 'quantity' => 12, 'unit_cost' => 8.5, 'position' => 1]);
        $labor = $source->items()->create(['category' => 'labor', 'description' => 'Install outlets', 'unit' => 'HR', 'quantity' => 6, 'unit_cost' => 70, 'position' => 2]);

        $this->actingAs($pm)->post(route('estimate-builder.import', $estimate), ['source_estimate_id' => $source->id])
            ->assertSessionHas('success', '2 lines imported from EST-2001.');

        $lines = $estimate->builderLines()->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(12, $lines[0]->material_qty);
        $this->assertEquals(8.5, $lines[0]->material_unit_price);
        $this->assertSame('takeoff', $lines[0]->source);
        $this->assertSame($material->id, $lines[0]->source_estimate_item_id);
        $this->assertEquals(6, $lines[1]->labor_hours);
        $this->assertEquals(70, $lines[1]->labor_rate);
        $this->assertSame($labor->id, $lines[1]->source_estimate_item_id);
        $this->assertEquals(15, $lines[0]->markup_pct);
        $this->assertSame($source->id, $estimate->fresh()->takeoff_source_estimate_id);

        // The takeoff is exactly as it was, and a second import adds nothing.
        $this->assertSame(2, $source->items()->count());
        $this->actingAs($pm)->post(route('estimate-builder.import', $estimate), ['source_estimate_id' => $source->id])
            ->assertSessionHas('warning');
        $this->assertSame(2, $estimate->builderLines()->count());
    }

    public function test_asking_for_approval_sends_it_on_and_locks_the_worksheet(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);

        // Nothing to approve yet, then nothing priced.
        $this->actingAs($pm)->post(route('estimate-builder.request-approval', $estimate), $this->payload([]))->assertSessionHasErrors('lines');
        $this->actingAs($pm)->post(route('estimate-builder.request-approval', $estimate), $this->payload([['description' => 'Unpriced', 'markup_pct' => 0]]))
            ->assertSessionHasErrors('lines');
        $this->assertSame('draft', $estimate->fresh()->status);

        $this->actingAs($pm)->post(route('estimate-builder.request-approval', $estimate), $this->payload($this->twoLines()))
            ->assertRedirect(route('estimates.review', $estimate))
            ->assertSessionHas('success');

        $estimate->refresh();
        $this->assertSame('sent', $estimate->status);
        $this->assertEquals(5713.48, $estimate->grand_total);

        // It now counts as waiting for approval on the dashboard, and can no longer be edited here.
        $this->actingAs($pm)->get('/home')->assertInertia(fn (Assert $page) => $page->where('summary.2.value', 1));
        $this->actingAs($pm)->get(route('estimate-builder.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page->where('estimate.locked', true)->where('estimate.status', 'sent'));
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()))->assertStatus(409);
        $this->actingAs($pm)->post(route('estimate-builder.import', $estimate), ['source_estimate_id' => $estimate->id])->assertStatus(409);
    }

    public function test_only_people_who_price_work_may_use_it(): void
    {
        [$volt, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);

        foreach (['Project Manager', 'Estimator', 'Supervisor', 'Admin', 'Owner'] as $role) {
            $user = User::factory()->create(['role' => $role, 'company_id' => $volt->id]);
            $this->actingAs($user)->get(route('estimate-builder.index'))->assertOk();
            $this->actingAs($user)->get(route('estimate-builder.show', $estimate))->assertOk();
        }

        foreach (['Foreman', 'Journeyman', 'Apprentice'] as $role) {
            $user = User::factory()->create(['role' => $role, 'company_id' => $volt->id]);
            $this->actingAs($user)->get(route('estimate-builder.index'))->assertForbidden();
            $this->actingAs($user)->get(route('estimate-builder.show', $estimate))->assertForbidden();
            $this->actingAs($user)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()))->assertForbidden();
        }
        $this->assertSame(0, $estimate->builderLines()->count());
    }

    public function test_it_belongs_to_the_company_and_only_to_estimates_the_builder_made(): void
    {
        [, $pm] = $this->company();
        [, $rival] = $this->company('Rival Co');
        $estimate = $this->builderEstimate($pm);

        // Another company's estimator cannot see or change it.
        $this->actingAs($rival)->get(route('estimate-builder.show', $estimate))->assertForbidden();
        $this->actingAs($rival)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()))->assertForbidden();
        $this->actingAs($rival)->get(route('estimate-builder.index'))->assertInertia(fn (Assert $page) => $page->has('estimates', 0)->has('projects', 0));
        $this->actingAs($rival)->post(route('estimate-builder.store'), ['project_id' => Project::sole()->id])->assertSessionHasErrors('project_id');

        // A colleague in the same company can.
        $colleague = User::factory()->create(['role' => 'Estimator', 'company_id' => $pm->company_id]);
        $this->actingAs($colleague)->get(route('estimate-builder.show', $estimate))->assertOk();

        // An estimate made another way is not the builder's to rewrite.
        $ordinary = Estimate::create([
            'user_id' => $pm->id, 'number' => 'EST-3001', 'client' => 'Acme', 'project' => 'P', 'issued_on' => '2026-01-01',
            'amount' => 0, 'status' => 'draft', 'kind' => 'standalone',
        ]);
        $this->actingAs($pm)->get(route('estimate-builder.show', $ordinary))->assertForbidden();
        $this->actingAs($pm)->put(route('estimate-builder.save', $ordinary), $this->payload($this->twoLines()))->assertForbidden();
    }

    public function test_the_price_list_is_the_companys_and_its_version_is_kept(): void
    {
        [$volt, $pm] = $this->company();
        [, $rival] = $this->company('Rival Co');
        foreach ([['Duplex outlet', 'EA', 'ELECTRICAL', 8.5, 0.4], ['Drywall 5/8 sheet', 'SF', 'DRYWALL', 0.62, 0.02]] as [$description, $unit, $section, $cost, $hours]) {
            PriceBookItem::create([
                'user_id' => $pm->id, 'match_key' => strtolower($description), 'unit' => $unit, 'description' => $description,
                'section' => $section, 'unit_material_cost' => $cost, 'unit_manhours' => $hours,
            ]);
        }
        $estimate = $this->builderEstimate($pm);

        $this->actingAs($pm)->get(route('estimate-builder.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->has('priceList', 2)
                ->where('priceList.0.commodity', 'DRYWALL')
                ->where('priceList.1.description', 'Duplex outlet')
                ->where('priceList.1.materialUnitPrice', 8.5)
                ->where('priceList.1.hoursPerUnit', 0.4)
                ->where('priceListVersion', fn ($v) => str_contains($v, '2 items')));

        $this->actingAs($pm)->getJson(route('estimate-builder.price-list', ['search' => 'outlet']))
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.description', 'Duplex outlet');

        // The version the estimate was priced from is written down when it is saved.
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()));
        $this->assertStringContainsString('2 items', $estimate->fresh()->commodity_version);

        // Another company has its own (empty) book, not this one.
        $this->actingAs($rival)->getJson(route('estimate-builder.price-list'))->assertOk()->assertJsonCount(0);
    }

    public function test_an_estimate_with_no_markup_of_its_own_lines_is_totalled_exactly_as_before(): void
    {
        [, $pm] = $this->company();
        $estimate = Estimate::create([
            'user_id' => $pm->id, 'number' => 'EST-4001', 'client' => 'Acme', 'project' => 'P', 'issued_on' => '2026-01-01',
            'amount' => 0, 'status' => 'draft', 'kind' => 'standalone', 'markup_pct' => 10, 'tax_pct' => 5,
        ]);
        $estimate->items()->create(['category' => 'material', 'description' => 'A', 'unit' => 'EA', 'quantity' => 3, 'unit_cost' => 33.33, 'position' => 1]);
        $estimate->items()->create(['category' => 'labor', 'description' => 'B', 'unit' => 'HR', 'quantity' => 2, 'unit_cost' => 41.11, 'position' => 2]);

        $estimate->recalculateTotals();
        $estimate->refresh();

        // 99.99 + 82.22 = 182.21; one 10% markup over the whole (18.22), then 5% tax on both.
        $this->assertEquals(182.21, $estimate->subtotal);
        $this->assertEquals(18.22, $estimate->markup_total);
        $this->assertEquals(10.02, $estimate->tax_total);
        $this->assertEquals(210.45, $estimate->grand_total);
    }

    public function test_only_this_projects_estimates_and_addenda_can_be_imported(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);
        $other = Project::create(['user_id' => $pm->id, 'name' => 'Some Other Project', 'client' => 'Acme', 'status' => 'draft']);

        $make = function (array $attributes) use ($pm): Estimate {
            static $n = 3000;
            $made = Estimate::create($attributes + [
                'user_id' => $pm->id, 'number' => 'EST-'.++$n, 'client' => 'Acme', 'project' => 'X', 'issued_on' => '2026-01-01',
                'amount' => 0, 'status' => 'draft', 'kind' => 'standalone',
            ]);
            $made->items()->create(['category' => 'material', 'description' => 'Line', 'unit' => 'EA', 'quantity' => 1, 'unit_cost' => 5, 'position' => 1]);

            return $made;
        };

        $sameEstimate = $make(['project_id' => $estimate->project_id]);
        $sameAddendum = $make(['project_id' => $estimate->project_id, 'kind' => 'addendum', 'parent_estimate_id' => $sameEstimate->id, 'addendum_number' => 1, 'addendum_name' => 'Extra lighting']);
        $otherProject = $make(['project_id' => $other->id]);
        $otherAddendum = $make(['project_id' => $other->id, 'kind' => 'addendum', 'addendum_number' => 1]);
        $noProject = $make(['project_id' => null]);
        $merged = $make(['project_id' => $estimate->project_id, 'kind' => 'merged']);
        $empty = Estimate::create([
            'user_id' => $pm->id, 'project_id' => $estimate->project_id, 'number' => 'EST-3999', 'client' => 'Acme', 'project' => 'X',
            'issued_on' => '2026-01-01', 'amount' => 0, 'status' => 'draft', 'kind' => 'standalone',
        ]);

        // The picker offers this project's estimate and its addendum — nothing else, the estimate first.
        $this->actingAs($pm)->get(route('estimate-builder.show', $estimate))
            ->assertInertia(fn (Assert $page) => $page
                ->has('importSources', 2)
                ->where('importSources.0.id', $sameEstimate->id)
                ->where('importSources.0.kind', 'estimate')
                ->where('importSources.1.id', $sameAddendum->id)
                ->where('importSources.1.kind', 'addendum')
                ->where('importSources.1.addendumNumber', 1)
                ->where('importSources.1.addendumName', 'Extra lighting'));

        // Naming any other one directly is refused just the same.
        foreach ([$otherProject, $otherAddendum, $noProject, $merged, $empty] as $refused) {
            $this->actingAs($pm)->post(route('estimate-builder.import', $estimate), ['source_estimate_id' => $refused->id])
                ->assertSessionHasErrors('source_estimate_id');
        }
        $this->assertSame(0, $estimate->builderLines()->count());

        // The addendum of this project goes in.
        $this->actingAs($pm)->post(route('estimate-builder.import', $estimate), ['source_estimate_id' => $sameAddendum->id])
            ->assertSessionHas('success');
        $this->assertSame(1, $estimate->builderLines()->count());
    }

    public function test_switched_off_the_builder_and_its_review_are_out_of_sight(): void
    {
        [, $pm] = $this->company();
        $estimate = $this->builderEstimate($pm);
        $this->actingAs($pm)->post(route('estimate-builder.request-approval', $estimate), $this->payload($this->twoLines()));
        $this->assertSame('sent', $estimate->fresh()->status);

        // On, it is offered in the menu.
        $this->actingAs($pm)->get(route('estimates.index'))->assertInertia(fn (Assert $page) => $page->where('features.estimateBuilder', true));

        config(['features.estimate_builder' => false]);

        // Off, the menu does not offer it and none of its addresses answer.
        $this->actingAs($pm)->get(route('estimates.index'))->assertInertia(fn (Assert $page) => $page->where('features.estimateBuilder', false));
        $this->actingAs($pm)->get(route('estimate-builder.index'))->assertNotFound();
        $this->actingAs($pm)->get(route('estimate-builder.show', $estimate))->assertNotFound();
        $this->actingAs($pm)->put(route('estimate-builder.save', $estimate), $this->payload($this->twoLines()))->assertNotFound();
        $this->actingAs($pm)->post(route('estimate-builder.store'), ['project_id' => Project::sole()->id])->assertNotFound();
        $this->actingAs($pm)->getJson(route('estimate-builder.price-list'))->assertNotFound();
        $this->actingAs($pm)->get(route('estimates.review', $estimate))->assertNotFound();
        $this->actingAs($pm)->post(route('estimates.approve', $estimate))->assertNotFound();
        $this->actingAs($pm)->post(route('estimates.return', $estimate), ['notes' => 'No'])->assertNotFound();

        // Nothing was decided, and the estimate opens in the ordinary place.
        $this->assertSame('sent', $estimate->fresh()->status);
        $this->actingAs($pm)->get(route('estimates.show', $estimate))->assertOk();

        // The rest of Estimates is untouched.
        $this->actingAs($pm)->get(route('estimates.index'))->assertOk();
    }
}
