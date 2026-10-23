<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Foreman;
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
        $member = Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'role' => Foreman::ROLE_JOURNEYMAN]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/clients', [
                'name' => 'Harborview Data Hall',
                'labor_rate' => 65,
                'website' => 'harborview.example',
                'contact_email' => 'dana@example.com',
                'contact_phone' => '(415) 555-0134',
                'member_ids' => [$member->id],
                'addresses' => [
                    ['label' => 'Main building', 'address' => '123 Main St', 'site_type' => 'commercial'],
                ],
            ])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'Harborview Data Hall');
        $response->assertJsonPath('data.laborRate', 65);
        $response->assertJsonPath('data.addresses.0.label', 'Main building');
        $response->assertJsonPath('data.addresses.0.isPrimary', true);
        $response->assertJsonPath('data.website', 'https://harborview.example');
        $response->assertJsonPath('data.contactEmail', 'dana@example.com');
        $response->assertJsonPath('data.members.0.name', 'Jamie Lin');
        $response->assertJsonPath('data.contacts.0.isPrimary', true);

        $this->assertDatabaseHas('clients', ['user_id' => $owner->id, 'name' => 'Harborview Data Hall']);
    }

    public function test_adding_a_client_needs_at_least_one_member(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/clients', ['name' => 'No Crew Co'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_ids']);
    }

    public function test_create_options_lists_the_register_and_pending_invitees(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'role' => Foreman::ROLE_JOURNEYMAN]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/clients/create-options')
            ->assertOk();

        $this->assertSame(['Jamie Lin'], array_column($response->json('data.members'), 'name'));
        $this->assertSame('Journeyman', $response->json('data.members.0.roleLabel'));
        $this->assertEquals((float) config('ai.estimating.labor_rate'), $response->json('data.defaultLaborRate'));
        $this->assertSame([], $response->json('data.pendingMembers'));
    }

    public function test_a_clients_contacts_can_be_added_edited_and_removed_and_the_primary_is_promoted(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($owner)];

        $first = $this->withHeaders($auth)->postJson("/api/v1/clients/{$client->id}/contacts", [
            'name' => 'Dana Ruiz', 'role' => 'Owner', 'email' => 'dana@example.com', 'phone' => '(415) 555-0134',
        ])->assertCreated();
        $this->assertTrue($first->json('data.isPrimary'));

        $second = $this->withHeaders($auth)->postJson("/api/v1/clients/{$client->id}/contacts", ['name' => 'Sam Ortiz'])->assertCreated();
        $this->assertFalse($second->json('data.isPrimary'));

        $this->withHeaders($auth)->putJson("/api/v1/clients/{$client->id}/contacts/{$second->json('data.id')}", [
            'name' => 'Sam Ortiz', 'role' => 'PM',
        ])->assertOk()->assertJsonPath('data.role', 'PM');

        $this->withHeaders($auth)->deleteJson("/api/v1/clients/{$client->id}/contacts/{$first->json('data.id')}")->assertOk();
        $this->assertTrue($client->contacts()->first()->is_primary);

        $this->withHeaders($auth)->postJson("/api/v1/clients/{$client->id}/contacts", ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_client_show_carries_summary_balance_and_activity_and_a_client_can_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Phase 1', 'client' => 'Apex Builders', 'status' => 'completed']);
        Project::create(['user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Phase 2', 'client' => 'Apex Builders', 'status' => 'processing']);
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($owner)];

        $show = $this->withHeaders($auth)->getJson("/api/v1/clients/{$client->id}")->assertOk();
        $this->assertSame(2, $show->json('data.projectSummary.total'));
        $this->assertSame(1, $show->json('data.projectSummary.completed'));
        $this->assertSame(1, $show->json('data.projectSummary.active'));
        $this->assertEquals(0, $show->json('data.outstandingBalance'));
        $this->assertSame([], $show->json('data.activity'));

        $this->withHeaders($auth)->deleteJson("/api/v1/clients/{$client->id}")->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $client->id]);
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

    public function test_takeoff_history_leaves_out_drafts_and_filters_sorts_and_lists_options(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $mk = fn (string $name, string $client, string $status, array $extra = []) => Project::create([
            'user_id' => $owner->id, 'name' => $name, 'client' => $client, 'status' => $status, ...$extra,
        ]);
        $alpha = $mk('Alpha Hall', 'Apex', 'completed', ['review_status' => 'pending', 'created_at' => now()->subDays(2)]);
        $bravo = $mk('Bravo Tower', 'Harbor', 'converted', ['created_at' => now()->subDay()]);
        $mk('Charlie Draft', 'Apex', 'draft');
        $run = $mk('Delta Run', 'Apex', 'processing', ['created_at' => now()]);

        $call = fn (string $query) => $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/takeoffs'.$query)->assertOk();

        $all = $call('');
        $this->assertSame([$run->id, $bravo->id, $alpha->id], collect($all->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame(['Apex', 'Harbor'], $all->json('data.clients'));
        $this->assertSame(['Alpha Hall', 'Bravo Tower', 'Delta Run'], array_column($all->json('data.projectOptions'), 'name'));

        $this->assertSame([$alpha->id], collect($call('?status=ready-for-review')->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame('ready-for-review', $call('?status=ready-for-review')->json('data.takeoffs.0.reviewStatus'));
        $this->assertSame([$bravo->id], collect($call('?client=Harbor')->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame([$alpha->id], collect($call('?project='.$alpha->id)->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame([$alpha->id, $bravo->id, $run->id], collect($call('?sort=name-asc')->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame([$alpha->id, $bravo->id, $run->id], collect($call('?sort=date-asc')->json('data.takeoffs'))->pluck('id')->all());
        $this->assertSame([$bravo->id], collect($call('?search=Tower')->json('data.takeoffs'))->pluck('id')->all());
    }

    public function test_a_takeoff_can_be_deleted_and_the_delete_undone(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Alpha Hall', 'client' => 'Apex', 'status' => 'completed']);
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($owner)];

        $this->withHeaders($auth)->deleteJson("/api/v1/takeoffs/{$project->id}")->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertSame([], $this->withHeaders($auth)->getJson('/api/v1/takeoffs')->json('data.takeoffs'));

        $this->withHeaders($auth)->postJson("/api/v1/takeoffs/{$project->id}/restore")->assertOk();
        $this->assertNotSoftDeleted('projects', ['id' => $project->id]);
        $this->assertCount(1, $this->withHeaders($auth)->getJson('/api/v1/takeoffs')->json('data.takeoffs'));
    }

    public function test_deleting_or_restoring_someone_elses_takeoff_is_forbidden(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $project = Project::create(['user_id' => $owner->id, 'name' => 'Alpha Hall', 'client' => 'Apex', 'status' => 'completed']);

        $stranger = User::factory()->create(['role' => 'Project Manager']);
        $company = \App\Models\CompanyProfile::create([
            'user_id' => $stranger->id, 'name' => 'Other Co', 'business_address' => '1 Main St', 'primary_contact' => 'A',
            'phone' => '(512) 555-0142', 'email' => 'other@x.test', 'timezone' => 'America/Chicago',
        ]);
        $stranger->forceFill(['company_id' => $company->id])->save();
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($stranger->fresh())];

        $this->withHeaders($auth)->deleteJson("/api/v1/takeoffs/{$project->id}")->assertForbidden();

        $project->delete();
        $this->withHeaders($auth)->postJson("/api/v1/takeoffs/{$project->id}/restore")->assertForbidden();
    }

    public function test_project_create_options_lists_clients_with_sites_members_and_teams(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Crew A']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders', 'team_id' => $team->id]);
        $client->addresses()->create(['label' => 'HQ', 'address' => '1 Apex Way', 'is_primary' => true, 'position' => 0, 'site_type' => 'commercial']);
        $member = Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'role' => Foreman::ROLE_JOURNEYMAN]);
        $client->members()->sync([$member->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->getJson('/api/v1/projects/create-options')
            ->assertOk();

        $this->assertSame('Apex Builders', $response->json('data.clients.0.name'));
        $this->assertSame('commercial', $response->json('data.clients.0.addresses.0.siteType'));
        $this->assertSame('Jamie Lin', $response->json('data.clients.0.members.0.name'));
        $this->assertSame(['Crew A'], array_column($response->json('data.teams'), 'name'));
    }

    public function test_a_project_is_opened_with_every_field_and_staffed_only_from_the_clients_crew(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Crew A']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $mine = Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'role' => Foreman::ROLE_JOURNEYMAN]);
        $stranger = Foreman::create(['name' => 'Not Theirs', 'initials' => 'NT', 'role' => Foreman::ROLE_JOURNEYMAN]);
        $client->members()->sync([$mine->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/projects', [
                'client_id' => $client->id,
                'name' => 'Harborview Phase 2',
                'description' => 'Second floor fit-out',
                'project_type' => 'commercial',
                'start_date' => '2026-11-01',
                'end_date' => '2026-12-01',
                'estimate_target_total' => 24850,
                'team_id' => $team->id,
                'member_ids' => [$mine->id, $stranger->id],
            ])
            ->assertCreated();

        $project = Project::findOrFail($response->json('data.id'));
        $this->assertSame('Second floor fit-out', $project->description);
        $this->assertSame('commercial', $project->project_type);
        $this->assertSame('2026-11-01', $project->started_at->toDateString());
        $this->assertSame([$mine->id], $project->members()->pluck('foremen.id')->all());
        $this->assertSame($team->id, $client->fresh()->team_id);
    }

    public function test_a_project_needs_a_team_when_members_are_picked_and_dates_must_be_in_order(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $member = Foreman::create(['name' => 'Jamie Lin', 'initials' => 'JL', 'role' => Foreman::ROLE_JOURNEYMAN]);
        $client->members()->sync([$member->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson('/api/v1/projects', [
                'client_id' => $client->id, 'name' => 'Phase 3', 'member_ids' => [$member->id],
                'start_date' => '2026-12-01', 'end_date' => '2026-11-01',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id', 'end_date']);
    }

    public function test_project_show_carries_the_workspace_tabs_and_a_project_can_be_deleted(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = Client::create(['user_id' => $owner->id, 'name' => 'Apex Builders']);
        $project = Project::create([
            'user_id' => $owner->id, 'client_id' => $client->id, 'name' => 'Phase 1', 'client' => 'Apex Builders',
            'status' => 'draft', 'description' => 'Shell and core',
        ]);
        $auth = ['Authorization' => 'Bearer '.$this->tokenFor($owner)];

        $show = $this->withHeaders($auth)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertSame('Shell and core', $show->json('data.description'));
        $this->assertSame(0, $show->json('data.drawingCount'));
        $this->assertSame([], $show->json('data.members'));
        $this->assertSame([], $show->json('data.invoices'));
        $this->assertSame([], $show->json('data.documents'));

        $this->withHeaders($auth)->deleteJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
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
