<?php

namespace Tests\Feature\Api;

use App\Models\Foreman;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mobile Team roster + pending approvals (`Api\V1\TeamController::index`) —
 * the same `foremen` table and `registration_source = mobile` pending-signup
 * query web's `TeamController`/`TechnicianController` use. Read-only in this
 * pass (no approve/reject wiring yet — see the controller doc comment).
 */
class MobileTeamTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/team')->assertUnauthorized();
    }

    public function test_returns_the_full_roster_and_pending_signups(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Team A']);
        $member = Foreman::create([
            'name' => 'Sam Rivera', 'initials' => 'SR', 'team_id' => $team->id,
            'role' => Foreman::ROLE_FOREMAN, 'started_on' => now()->subDays(3),
        ]);

        $pending = User::factory()->create([
            'name' => 'New Signup', 'role' => 'Journeyman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_PENDING_APPROVAL,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'members' => ['*' => ['id', 'name', 'initials', 'role', 'teamId', 'teamName', 'phone', 'email', 'joinedOn']],
                    'pendingApprovals' => ['*' => ['id', 'name', 'email', 'phone', 'role', 'createdAt']],
                    'meta' => ['currentPage', 'lastPage', 'perPage', 'total'],
                ],
            ]);

        $memberIds = collect($response->json('data.members'))->pluck('id');
        $this->assertSame([$member->id], $memberIds->all());
        $this->assertSame('Team A', $response->json('data.members.0.teamName'));

        $pendingIds = collect($response->json('data.pendingApprovals'))->pluck('id');
        $this->assertSame([$pending->id], $pendingIds->all());
    }

    public function test_a_synced_technician_no_longer_appears_in_pending_approvals(): void
    {
        // Once `TechnicianController::syncForemanRoster()` gives a mobile
        // signup a `foremen` row, they live on their team's card, not in the
        // separate pending list — same rule web's `technicians()` enforces
        // (`whereDoesntHave('foreman')`).
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Team A']);
        $synced = User::factory()->create([
            'name' => 'Already Synced', 'role' => 'Journeyman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_ACTIVE,
        ]);
        Foreman::create([
            'name' => 'Already Synced', 'initials' => 'AS', 'team_id' => $team->id,
            'role' => Foreman::ROLE_JOURNEYMAN, 'user_id' => $synced->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/team')
            ->assertOk();

        $this->assertSame([], $response->json('data.pendingApprovals'));
    }

    public function test_roster_pagination_meta_and_cap(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        for ($i = 0; $i < 5; $i++) {
            Foreman::create(['name' => "Member {$i}", 'initials' => 'M'.$i]);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/team?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data.members'));
        $this->assertSame(3, $response->json('data.meta.lastPage'));

        $capped = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/team?per_page=500')
            ->assertOk();
        $this->assertSame(50, $capped->json('data.meta.perPage'));
    }

    // --- Add member (store) -------------------------------------------------

    public function test_a_manager_can_add_a_member_and_it_creates_a_real_mobile_login(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Team A']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson('/api/v1/team', [
                'name' => 'Casey Morgan',
                'role' => Foreman::ROLE_JOURNEYMAN,
                'team_id' => $team->id,
                'email' => 'casey@example.com',
                'password' => 'Password!234',
                'password_confirmation' => 'Password!234',
            ])
            ->assertOk();

        $memberId = $response->json('data.id');
        $member = Foreman::findOrFail($memberId);
        $this->assertSame('Casey Morgan', $member->name);
        $this->assertSame('CM', $member->initials);
        $this->assertSame($team->id, $member->team_id);
        $this->assertNotNull($member->user_id);

        $account = User::find($member->user_id);
        $this->assertSame('casey@example.com', $account->email);
        $this->assertSame('Journeyman', $account->role);
    }

    public function test_add_member_requires_manager_role(): void
    {
        $technician = User::factory()->create(['role' => 'Journeyman']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->postJson('/api/v1/team', [
                'name' => 'Casey Morgan', 'role' => Foreman::ROLE_JOURNEYMAN,
                'email' => 'casey2@example.com', 'password' => 'Password!234',
                'password_confirmation' => 'Password!234',
            ])
            ->assertForbidden();
    }

    public function test_add_member_rejects_a_duplicate_email(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson('/api/v1/team', [
                'name' => 'Casey Morgan', 'role' => Foreman::ROLE_JOURNEYMAN,
                'email' => 'taken@example.com', 'password' => 'Password!234',
                'password_confirmation' => 'Password!234',
            ])
            ->assertStatus(422);
    }

    // --- Edit member (update) -----------------------------------------------

    public function test_a_manager_can_edit_a_member_and_initials_are_rederived(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $team = Team::create(['name' => 'Team A']);
        $member = Foreman::create([
            'name' => 'Sam Rivera', 'initials' => 'SR', 'role' => Foreman::ROLE_JOURNEYMAN,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/team/{$member->id}", [
                'name' => 'Sam Rivera Cruz',
                'role' => Foreman::ROLE_FOREMAN,
                'team_id' => $team->id,
                'notes' => 'Promoted to foreman',
            ])
            ->assertOk();

        $fresh = $member->fresh();
        $this->assertSame('Sam Rivera Cruz', $fresh->name);
        $this->assertSame('SR', $fresh->initials);
        $this->assertSame(Foreman::ROLE_FOREMAN, $fresh->role);
        $this->assertSame($team->id, $fresh->team_id);
        $this->assertSame('Promoted to foreman', $fresh->notes);
    }

    public function test_edit_member_does_not_require_email_or_password(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $member = Foreman::create(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => Foreman::ROLE_JOURNEYMAN]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->putJson("/api/v1/team/{$member->id}", ['name' => 'Sam Rivera', 'role' => Foreman::ROLE_JOURNEYMAN])
            ->assertOk();
    }

    // --- Remove member (destroy) ---------------------------------------------

    public function test_a_manager_can_remove_a_member_with_no_task_history(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $member = Foreman::create(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => Foreman::ROLE_JOURNEYMAN]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->deleteJson("/api/v1/team/{$member->id}")
            ->assertOk();

        $this->assertNull(Foreman::find($member->id));
    }

    public function test_removing_a_member_with_task_history_is_refused(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        $member = Foreman::create(['name' => 'Sam Rivera', 'initials' => 'SR', 'role' => Foreman::ROLE_JOURNEYMAN]);
        $job = \App\Models\Job::create(['user_id' => $manager->id, 'name' => 'Job A', 'client' => 'Apex', 'status' => 'in-progress']);
        $schedule = \App\Models\JobSchedule::create(['job_id' => $job->id, 'working_days' => [1, 2, 3, 4, 5]]);
        \App\Models\JobTask::create([
            'job_schedule_id' => $schedule->id, 'job_id' => $job->id,
            'foreman_id' => $member->id, 'title' => 'Task A', 'status' => 'in-progress',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->deleteJson("/api/v1/team/{$member->id}")
            ->assertStatus(422);

        $this->assertNotNull(Foreman::find($member->id));
    }

    // --- Add team (crew group) -----------------------------------------------

    public function test_a_manager_can_add_a_team(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson('/api/v1/teams', ['name' => 'Crew Delta'])
            ->assertOk();

        $this->assertSame('Crew Delta', $response->json('data.name'));
        $this->assertDatabaseHas('teams', ['name' => 'Crew Delta']);
    }

    public function test_add_team_requires_manager_role(): void
    {
        $technician = User::factory()->create(['role' => 'Journeyman']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->postJson('/api/v1/teams', ['name' => 'Crew Delta'])
            ->assertForbidden();
    }

    public function test_add_team_rejects_a_duplicate_name(): void
    {
        $manager = User::factory()->create(['role' => 'Project Manager']);
        Team::create(['name' => 'Crew Delta']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson('/api/v1/teams', ['name' => 'Crew Delta'])
            ->assertStatus(422);
    }
}
