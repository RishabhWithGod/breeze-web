<?php

namespace Tests\Feature;

use App\Models\CompanyProfile;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Estimate Builder through the app's API: start, price, save, send for review. */
class EstimateBuilderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pm = User::factory()->create(['role' => 'Project Manager']);
        $company = CompanyProfile::create([
            'user_id' => $this->pm->id, 'name' => 'Volt & Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'o@x.test', 'timezone' => 'America/Chicago',
        ]);
        $company->forceFill(['onboarding_finished_at' => now()])->save();
        $this->pm->forceFill(['company_id' => $company->id])->save();
        $this->pm = $this->pm->fresh();
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken);
    }

    private function started(): Estimate
    {
        $project = Project::create(['user_id' => $this->pm->id, 'name' => 'Riverside', 'client' => 'Acme', 'status' => 'draft']);
        $this->as($this->pm)->postJson('/api/v1/estimate-builder', ['project_id' => $project->id])->assertCreated();

        return Estimate::where('builder_managed', true)->firstOrFail();
    }

    private function payload(array $lines, array $settings = []): array
    {
        return ['lines' => $lines, 'settings' => ['tax_pct' => 8, 'markup_pct' => 15, 'labor_rate' => 65, 'scope_of_work' => 'All labor and material.', ...$settings]];
    }

    private function line(array $extra = []): array
    {
        return ['description' => 'Duplex outlet', 'unit' => 'ea', 'material_qty' => 10, 'material_unit_price' => 4, 'labor_hours' => 2, 'labor_rate' => 60, 'markup_pct' => 15, ...$extra];
    }

    public function test_the_front_door_lists_estimates_and_projects_and_a_new_one_starts_as_a_draft(): void
    {
        $estimate = $this->started();
        $this->assertSame('draft', $estimate->status);

        $this->as($this->pm)->getJson('/api/v1/estimate-builder')
            ->assertOk()
            ->assertJsonCount(1, 'data.estimates')
            ->assertJsonPath('data.estimates.0.number', $estimate->number)
            ->assertJsonCount(1, 'data.projects');
    }

    public function test_the_worksheet_is_saved_and_read_back(): void
    {
        $estimate = $this->started();

        $this->as($this->pm)->putJson("/api/v1/estimate-builder/{$estimate->id}", $this->payload([$this->line()]))
            ->assertOk()
            ->assertJsonPath('message', 'Draft saved.');

        $this->as($this->pm)->getJson("/api/v1/estimate-builder/{$estimate->id}")
            ->assertOk()
            ->assertJsonPath('data.estimate.locked', false)
            ->assertJsonPath('data.estimate.markupPct', 15)
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.description', 'Duplex outlet');
    }

    public function test_sending_for_review_needs_priced_lines_and_a_scope_and_then_locks_it(): void
    {
        $estimate = $this->started();
        $url = "/api/v1/estimate-builder/{$estimate->id}/request-approval";

        $this->as($this->pm)->postJson($url, $this->payload([]))->assertStatus(422)->assertJsonValidationErrors('lines');
        $this->as($this->pm)->postJson($url, $this->payload([$this->line()], ['scope_of_work' => '']))->assertStatus(422)->assertJsonValidationErrors('scope_of_work');
        $this->as($this->pm)->postJson($url, $this->payload([$this->line(['material_unit_price' => 0])]))->assertStatus(422)->assertJsonValidationErrors('lines');

        $this->as($this->pm)->postJson($url, $this->payload([$this->line()]))->assertOk();
        $this->assertNotSame('draft', $estimate->fresh()->status);

        // Sent: no longer editable here.
        $this->as($this->pm)->putJson("/api/v1/estimate-builder/{$estimate->id}", $this->payload([$this->line()]))->assertStatus(409);
        $this->as($this->pm)->getJson("/api/v1/estimate-builder/{$estimate->id}")->assertJsonPath('data.estimate.locked', true);
    }

    public function test_only_people_who_price_work_can_use_it(): void
    {
        $tech = User::factory()->create(['role' => 'Journeyman', 'company_id' => $this->pm->company_id]);
        $tech->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->as($tech)->getJson('/api/v1/estimate-builder')->assertForbidden();
        $this->as($tech)->getJson('/api/v1/estimate-builder/price-list')->assertForbidden();
        $this->as($this->pm)->getJson('/api/v1/estimate-builder/price-list')->assertOk();
    }
}
