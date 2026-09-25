<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\TechnicianController;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TechnicianApplicationStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mobile approve/reject (`Api\V1\TechnicianController`) — the JSON
 * counterpart to web's `TechnicianController::approve`/`reject`, sharing the
 * same `TechnicianApprovalService` and validation rules. See
 * `TechnicianOnboardingTest` for the exhaustive web-side coverage of the
 * shared approval behaviour; this file only pins the mobile-specific
 * surface: JSON responses, status codes, and the `Api\V1\TeamController`
 * payload the approve picker depends on.
 */
class MobileTechnicianApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function makeManager(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'Project Manager', ...$attributes]);
    }

    private function makeMobileTechnician(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'Journeyman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_PENDING_APPROVAL,
            ...$attributes,
        ]);
    }

    public function test_a_manager_can_approve_a_technician_with_a_team_and_role(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/technicians/{$technician->id}/approve", [
                'team_id' => $team->id,
                'role' => 'Foreman',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $technician->refresh();
        $this->assertTrue($technician->isActive());
        $this->assertSame('Foreman', $technician->role);
        $this->assertDatabaseHas('foremen', ['user_id' => $technician->id, 'team_id' => $team->id]);
        Notification::assertSentTo($technician, TechnicianApplicationStatusChanged::class, function ($notification) {
            return $notification->reason === TechnicianApplicationStatusChanged::APPROVED;
        });
    }

    public function test_approving_without_a_team_or_role_is_a_validation_error(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/technicians/{$technician->id}/approve", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['team_id', 'role']);

        $this->assertTrue($technician->fresh()->isPending());
    }

    public function test_a_manager_can_reject_a_technician(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/technicians/{$technician->id}/reject")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($technician->fresh()->isRejected());
    }

    public function test_a_non_manager_cannot_approve_or_reject(): void
    {
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $other = $this->makeMobileTechnician();

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($technician))
            ->postJson("/api/v1/technicians/{$other->id}/approve", ['team_id' => 1, 'role' => 'Foreman'])
            ->assertForbidden();

        $this->assertTrue($other->fresh()->isPending());
    }

    public function test_a_web_created_foreman_is_not_reachable_through_the_mobile_endpoint(): void
    {
        $manager = $this->makeManager();
        $foreman = User::factory()->create(['role' => 'Foreman']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->postJson("/api/v1/technicians/{$foreman->id}/reject")
            ->assertNotFound();
    }

    public function test_team_index_includes_team_and_role_options_for_the_approve_picker(): void
    {
        $manager = $this->makeManager();
        $team = Team::create(['name' => 'North Crew']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($manager))
            ->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonPath('data.teamOptions.0.name', $team->name)
            ->assertJsonPath('data.roleOptions', TechnicianController::ROLES);
    }
}
