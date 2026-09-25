<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Job;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile Clients / Projects / AI Takeoffs lists — all three follow the
 * same `ownedBy()` (single-owner `user_id`) scoping as `Estimate`/`Job`,
 * with no company/team concept anywhere in this schema.
 */
class MobileClientsProjectsTakeoffsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    // --- Clients ---------------------------------------------------------

    public function test_clients_requires_authentication(): void
    {
        $this->getJson('/api/v1/clients')->assertUnauthorized();
    }

    public function test_a_user_sees_only_their_own_clients(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);

        $mine = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        Client::create(['user_id' => $other->id, 'name' => 'Riverside LLC']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/clients')
            ->assertOk();

        $ids = collect($response->json('data.clients'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_clients_response_shape_and_pagination(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/clients')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'clients' => ['*' => ['id', 'name', 'projectCount', 'siteCount', 'primarySite', 'createdAt']],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);
    }

    public function test_clients_newest_touched_first(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $older = Client::create(['user_id' => $owner->id, 'name' => 'Older Client']);
        $newer = Client::create(['user_id' => $owner->id, 'name' => 'Newer Client']);
        $older->touch();
        $newer->forceFill(['updated_at' => now()->addMinute()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/clients')
            ->assertOk();

        $ids = collect($response->json('data.clients'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    public function test_a_client_can_be_created_with_addresses(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/clients', [
                'name' => 'Harborview Data Hall',
                'labor_rate' => 65,
                'addresses' => [
                    ['label' => 'Main building', 'address' => '123 Main St', 'site_type' => 'commercial'],
                ],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Harborview Data Hall');
        $response->assertJsonPath('data.laborRate', 65);
        $response->assertJsonPath('data.addresses.0.label', 'Main building');
        $response->assertJsonPath('data.addresses.0.isPrimary', true);

        $this->assertDatabaseHas('clients', ['user_id' => $owner->id, 'name' => 'Harborview Data Hall']);
    }

    public function test_client_name_is_required_and_unique_per_owner(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/clients', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/clients', ['name' => 'Apex Builders'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_client_can_be_updated_but_addresses_are_untouched(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders', 'labor_rate' => 50]);
        $client->addresses()->create(['label' => 'HQ', 'address' => '1 Apex Way', 'is_primary' => true, 'position' => 0]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/clients/{$client->id}", ['name' => 'Apex Builders LLC', 'labor_rate' => 75])
            ->assertOk();

        $response->assertJsonPath('data.name', 'Apex Builders LLC');
        $response->assertJsonPath('data.laborRate', 75);
        $response->assertJsonCount(1, 'data.addresses');
        $this->assertSame('Apex Builders LLC', $client->fresh()->name);
    }

    public function test_a_client_cannot_be_updated_by_someone_else(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->putJson("/api/v1/clients/{$client->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    // --- Projects ----------------------------------------------------------

    public function test_projects_requires_authentication(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
    }

    public function test_a_project_can_be_updated(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $client->addresses()->create(['label' => 'HQ', 'address' => '1 Apex Way', 'is_primary' => true, 'position' => 0]);
        $project = Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'AP NEQ', 'client' => 'Apex', 'status' => 'draft']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/projects/{$project->id}", [
                'name' => 'AP NEQ Renamed',
                'client_id' => $client->id,
                'estimate_target_total' => 15000,
            ])
            ->assertOk();

        $response->assertJsonPath('data.name', 'AP NEQ Renamed');
        $this->assertSame('AP NEQ Renamed', $project->fresh()->name);
        $this->assertSame('1 Apex Way', $project->fresh()->location);
        $this->assertEquals(15000, $project->fresh()->estimate_target_total);
    }

    public function test_a_project_cannot_be_updated_by_someone_else(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $project = Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'AP NEQ', 'client' => 'Apex', 'status' => 'draft']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->putJson("/api/v1/projects/{$project->id}", ['name' => 'Hijacked', 'client_id' => $client->id])
            ->assertForbidden();
    }

    public function test_a_user_sees_only_their_own_projects(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);

        $mine = Project::create(['user_id' => $owner->id, 'name' => 'AP NEQ', 'client' => 'Apex', 'status' => 'draft']);
        Project::create(['user_id' => $other->id, 'name' => 'Other Project', 'client' => 'Other', 'status' => 'draft']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects')
            ->assertOk();

        $ids = collect($response->json('data.projects'))->pluck('id');
        $this->assertSame([$mine->id], $ids->all());
    }

    public function test_projects_list_includes_real_job_and_estimate_counts(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $project = Project::create(['user_id' => $owner->id, 'name' => 'AP NEQ', 'client' => 'Apex', 'status' => 'draft']);
        \App\Models\Job::create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Job 1', 'client' => 'Apex', 'status' => 'in-progress']);
        \App\Models\Job::create(['user_id' => $owner->id, 'project_id' => $project->id, 'name' => 'Job 2', 'client' => 'Apex', 'status' => 'in-progress']);
        \App\Models\Estimate::create([
            'user_id' => $owner->id, 'project_id' => $project->id, 'number' => 'EST-0001',
            'client' => 'Apex', 'project' => 'AP NEQ', 'issued_on' => now(), 'amount' => 100,
            'status' => 'draft', 'kind' => \App\Models\Estimate::KIND_STANDALONE,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects')
            ->assertOk();

        $this->assertSame(2, $response->json('data.projects.0.jobCount'));
        $this->assertSame(1, $response->json('data.projects.0.estimateCount'));
    }

    public function test_projects_newest_updated_first(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $older = Project::create(['user_id' => $owner->id, 'name' => 'Older', 'client' => 'Apex', 'status' => 'draft']);
        $newer = Project::create(['user_id' => $owner->id, 'name' => 'Newer', 'client' => 'Apex', 'status' => 'draft']);
        $older->touch();
        $newer->forceFill(['updated_at' => now()->addMinute()])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects')
            ->assertOk();

        $ids = collect($response->json('data.projects'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    public function test_projects_pagination_meta_and_cap(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        for ($i = 0; $i < 5; $i++) {
            Project::create(['user_id' => $owner->id, 'name' => "Project {$i}", 'client' => 'Apex', 'status' => 'draft']);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data.projects'));
        $this->assertSame(3, $response->json('data.meta.lastPage'));

        $capped = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects?per_page=500')
            ->assertOk();
        $this->assertSame(50, $capped->json('data.meta.perPage'));
    }

    // --- AI Takeoffs (Project, `created_at` desc) ---------------------------

    public function test_takeoffs_requires_authentication(): void
    {
        $this->getJson('/api/v1/takeoffs')->assertUnauthorized();
    }

    public function test_takeoffs_are_scoped_to_the_owner_and_newest_first(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);

        $older = Project::create([
            'user_id' => $owner->id, 'name' => 'Older Takeoff', 'client' => 'Apex',
            'status' => 'completed', 'created_at' => now()->subDay(),
        ]);
        $newer = Project::create([
            'user_id' => $owner->id, 'name' => 'Newer Takeoff', 'client' => 'Apex',
            'status' => 'processing', 'created_at' => now(),
        ]);
        Project::create(['user_id' => $other->id, 'name' => 'Not Mine', 'client' => 'Other', 'status' => 'draft']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/takeoffs')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'takeoffs' => ['*' => ['id', 'projectName', 'clientName', 'drawingFileName', 'status', 'uploadedAt', 'sheetCount', 'symbolsDetected']],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);

        $ids = collect($response->json('data.takeoffs'))->pluck('id');
        $this->assertSame([$newer->id, $older->id], $ids->all());
    }

    // --- Jobs (Edit) ---------------------------------------------------------

    public function test_a_job_can_be_updated_including_its_site(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $address = $client->addresses()->create(['label' => 'HQ', 'address' => '1 Apex Way', 'is_primary' => true, 'position' => 0]);
        $team = Team::create(['name' => 'Crew A']);
        $job = Job::create([
            'user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Rewire Floor 2',
            'client' => 'Apex Builders', 'status' => 'draft', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/jobs/{$job->id}", [
                'name' => 'Rewire Floor 2 — Updated',
                'description' => 'Panel swap included',
                'job_type' => 'commercial',
                'team_id' => $team->id,
                'start_date' => '2026-10-02',
                'end_date' => '2026-10-12',
                'address_id' => $address->id,
            ])
            ->assertOk();

        $response->assertJsonPath('data.name', 'Rewire Floor 2 — Updated');
        $response->assertJsonPath('data.teamId', $team->id);
        $response->assertJsonPath('data.addressId', $address->id);

        $fresh = $job->fresh();
        $this->assertSame('Rewire Floor 2 — Updated', $fresh->name);
        $this->assertSame('1 Apex Way', $fresh->location);
        $this->assertSame($team->id, $fresh->team_id);
    }

    public function test_a_job_cannot_be_updated_by_someone_else(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $team = Team::create(['name' => 'Crew A']);
        $job = Job::create([
            'user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Rewire Floor 2',
            'client' => 'Apex Builders', 'status' => 'draft',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->putJson("/api/v1/jobs/{$job->id}", [
                'name' => 'Hijacked', 'team_id' => $team->id,
                'start_date' => '2026-10-01', 'end_date' => '2026-10-02',
            ])
            ->assertForbidden();
    }

    public function test_a_completed_job_cannot_be_updated(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $team = Team::create(['name' => 'Crew A']);
        $job = Job::create([
            'user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Rewire Floor 2',
            'client' => 'Apex Builders', 'status' => 'completed',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/jobs/{$job->id}", [
                'name' => 'Rewire Floor 2', 'team_id' => $team->id,
                'start_date' => '2026-10-01', 'end_date' => '2026-10-02',
            ])
            ->assertStatus(409);
    }
}
