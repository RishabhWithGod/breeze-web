<?php

namespace Tests\Feature;

use App\Http\Controllers\TechnicianController;
use App\Models\Foreman;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TechnicianApplicationStatusChanged;
use App\Notifications\TechnicianSignupReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Technician self-signup → pending approval → manager approval / rejection.
 *
 * The one rule worth pinning above everything else: a technician who signs
 * up from the mobile app must be able to authenticate (so the app can show
 * them their own status) but must not be able to reach a single job/task/
 * timer endpoint until a manager approves them — `EnsureAccountIsActive`
 * is the only thing standing between "signed up" and "full access", so this
 * is really a test that the gate holds, not a parallel check that happens to
 * agree with it today.
 *
 * A second rule pinned here: `role` becomes a real operational role
 * (`Foreman`/`Journeyman`/`Apprentice`) immediately, not a marker — only
 * `registration_source` identifies "this came from the mobile app", so the
 * Teams page can still find and list them once a manager has corrected their
 * role away from the 'Journeyman' default.
 */
class TechnicianOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function makeManager(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'Project Manager', ...$attributes]);
    }

    /** A technician exactly as `AuthController::register()` creates one. */
    private function makeMobileTechnician(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'Journeyman',
            'registration_source' => User::SOURCE_MOBILE,
            'status' => User::STATUS_PENDING_APPROVAL,
            ...$attributes,
        ]);
    }

    public function test_registering_creates_a_pending_technician_and_returns_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'phone' => '2125551234',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.status', User::STATUS_PENDING_APPROVAL)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role', 'status']]]);

        $user = User::where('email', 'jamie@example.com')->sole();
        $this->assertSame('Journeyman', $user->role);
        $this->assertTrue($user->isPending());
        $this->assertTrue($user->isFromMobile());
        $this->assertTrue(Hash::check('correct-password', $user->password));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_a_web_created_user_is_not_treated_as_a_mobile_signup(): void
    {
        // The DB default for a plain `User::factory()->create()` — no mobile
        // signup involved — must never be mistaken for one.
        $user = User::factory()->create(['role' => 'Foreman'])->fresh();

        $this->assertFalse($user->isFromMobile());
        $this->assertSame('web', $user->registration_source);
    }

    public function test_registering_notifies_every_manager(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $other = User::factory()->create(['role' => 'Electrician']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])->assertCreated();

        Notification::assertSentTo($manager, TechnicianSignupReceived::class);
        Notification::assertNotSentTo($other, TechnicianSignupReceived::class);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jamie Rivera',
            'email' => 'taken@example.com',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_a_pending_technician_can_check_their_own_status_but_not_see_jobs(): void
    {
        $user = $this->makeMobileTechnician();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_PENDING_APPROVAL);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/jobs')
            ->assertStatus(403);
    }

    public function test_a_pending_technician_can_still_log_out(): void
    {
        $user = $this->makeMobileTechnician();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();
    }

    public function test_an_active_technician_can_reach_protected_endpoints(): void
    {
        $user = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/jobs')
            ->assertOk();
    }

    public function test_a_rejected_technician_is_blocked_the_same_way_as_pending(): void
    {
        $user = $this->makeMobileTechnician(['status' => User::STATUS_REJECTED]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/jobs')
            ->assertStatus(403);
    }

    public function test_a_manager_can_approve_a_technician_and_assign_a_team(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $technician->refresh();
        $this->assertTrue($technician->isActive());
        $this->assertSame($manager->id, $technician->approved_by);
        $this->assertNotNull($technician->approved_at);

        $this->assertDatabaseHas('team_members', [
            'user_id' => $technician->id,
            'team_id' => $team->id,
        ]);

        Notification::assertSentTo($technician, TechnicianApplicationStatusChanged::class, function ($notification) {
            return $notification->reason === TechnicianApplicationStatusChanged::APPROVED;
        });
    }

    public function test_a_manager_can_approve_and_correct_the_role_to_foreman(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $this->assertSame('Foreman', $technician->fresh()->role);
    }

    public function test_approving_with_an_invalid_role_is_rejected(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Owner'])
            ->assertSessionHasErrors('role');

        $this->assertTrue($technician->fresh()->isPending());
    }

    public function test_approving_without_a_team_is_rejected(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['role' => 'Foreman'])
            ->assertSessionHasErrors('team_id');

        $this->assertTrue($technician->fresh()->isPending());
    }

    public function test_approving_without_a_role_is_rejected(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id])
            ->assertSessionHasErrors('role');

        $this->assertTrue($technician->fresh()->isPending());
    }

    public function test_approving_a_technician_syncs_them_onto_their_teams_roster_and_off_the_technicians_list(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['name' => 'Jamie Rivera']);
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $this->assertDatabaseHas('foremen', [
            'user_id' => $technician->id,
            'team_id' => $team->id,
            'role' => 'foreman',
        ]);

        $this->actingAs($manager)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                // Synced onto the real roster now — the separate technician
                // list is only for those who are not on it yet.
                ->has('activeTechnicians', 0)
                ->has('teams.data', 1)
                ->where('teams.data.0.name', 'North Crew')
                ->where('teams.data.0.members.0.name', 'Jamie Rivera')
                ->where('teams.data.0.members.0.roleLabel', 'Foreman'));
    }

    public function test_assigning_a_team_and_role_moves_an_active_technician_onto_the_roster(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['name' => 'Alex Chen', 'status' => User::STATUS_ACTIVE]);
        $team = Team::create(['name' => 'South Crew']);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $this->assertDatabaseHas('foremen', [
            'user_id' => $technician->id,
            'team_id' => $team->id,
            'role' => 'foreman',
        ]);

        $this->actingAs($manager)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('activeTechnicians', 0)
                ->where('teams.data.0.members.0.name', 'Alex Chen'));
    }

    public function test_assigning_only_a_team_still_syncs_using_the_technicians_existing_role(): void
    {
        // `makeMobileTechnician()` already starts with the valid default
        // 'Journeyman' — a team is the only thing this call is missing before
        // both are known and the sync can happen.
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $team = Team::create(['name' => 'South Crew']);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $team->id])
            ->assertRedirect();

        $this->assertDatabaseHas('foremen', [
            'user_id' => $technician->id,
            'team_id' => $team->id,
            'role' => 'journeyman',
        ]);
    }

    public function test_approving_a_technician_sets_their_joined_date_to_the_approval_date(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $this->assertDatabaseHas('foremen', [
            'user_id' => $technician->id,
            'started_on' => $technician->fresh()->approved_at->toDateString(),
        ]);
    }

    public function test_correcting_a_technicians_team_later_does_not_overwrite_an_already_set_joined_date(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $teamOne = Team::create(['name' => 'North Crew']);
        $teamTwo = Team::create(['name' => 'South Crew']);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $teamOne->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $originalJoinedOn = Foreman::where('user_id', $technician->id)->value('started_on');

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $teamTwo->id])
            ->assertRedirect();

        $this->assertSame(
            (string) $originalJoinedOn,
            (string) Foreman::where('user_id', $technician->id)->value('started_on'),
        );
    }

    public function test_assigning_a_team_does_not_sync_while_the_technicians_role_is_not_a_valid_one(): void
    {
        // A technician approved before roles were validated can be sitting
        // on a stale value like the old free-text "Technician" — giving them
        // a team alone should not put a half-correct row on the register.
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE, 'role' => 'Technician']);
        $team = Team::create(['name' => 'South Crew']);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $team->id])
            ->assertRedirect();

        $this->assertDatabaseMissing('foremen', ['user_id' => $technician->id]);
    }

    public function test_a_manager_can_re_approve_a_rejected_technician(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_REJECTED]);
        $team = Team::create(['name' => 'North Crew']);

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/approve", ['team_id' => $team->id, 'role' => 'Foreman'])
            ->assertRedirect();

        $technician->refresh();
        $this->assertTrue($technician->isActive());
        $this->assertSame('Foreman', $technician->role);
        $this->assertDatabaseHas('team_members', ['user_id' => $technician->id, 'team_id' => $team->id]);
    }

    public function test_a_manager_can_correct_an_already_active_technicians_role_and_team(): void
    {
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $team = Team::create(['name' => 'South Crew']);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['role' => 'Foreman'])
            ->assertRedirect();
        $this->assertSame('Foreman', $technician->fresh()->role);

        $this->actingAs($manager)
            ->put("/technicians/{$technician->id}/team", ['team_id' => $team->id])
            ->assertRedirect();
        $this->assertDatabaseHas('team_members', ['user_id' => $technician->id, 'team_id' => $team->id]);
    }

    public function test_a_manager_can_reject_a_technician(): void
    {
        Notification::fake();
        $manager = $this->makeManager();
        $technician = $this->makeMobileTechnician();

        $this->actingAs($manager)
            ->post("/technicians/{$technician->id}/reject")
            ->assertRedirect();

        $this->assertTrue($technician->fresh()->isRejected());
        Notification::assertSentTo($technician, TechnicianApplicationStatusChanged::class, function ($notification) {
            return $notification->reason === TechnicianApplicationStatusChanged::REJECTED;
        });
    }

    public function test_a_technician_cannot_approve_another_technician(): void
    {
        $technician = $this->makeMobileTechnician(['status' => User::STATUS_ACTIVE]);
        $other = $this->makeMobileTechnician();

        $this->actingAs($technician)
            ->post("/technicians/{$other->id}/approve")
            ->assertForbidden();

        $this->assertTrue($other->fresh()->isPending());
    }

    public function test_a_web_created_foreman_is_not_reachable_through_technician_actions(): void
    {
        $manager = $this->makeManager();
        $foreman = User::factory()->create(['role' => 'Foreman']);

        $this->actingAs($manager)
            ->post("/technicians/{$foreman->id}/approve")
            ->assertNotFound();
    }

    public function test_a_guest_cannot_reach_the_technicians_page(): void
    {
        $this->get('/technicians')->assertRedirect('/login');
    }

    public function test_the_old_technicians_address_redirects_to_teams(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager)->get('/technicians')->assertRedirect('/teams');
    }

    public function test_the_teams_page_shows_pending_technicians_for_a_manager_to_approve(): void
    {
        $manager = $this->makeManager();
        $team = Team::create(['name' => 'North Crew']);
        $this->makeMobileTechnician(['name' => 'Jamie Rivera']);
        $this->makeMobileTechnician(['name' => 'Alex Chen', 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($manager)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                ->where('canApproveTechnicians', true)
                ->has('pendingTechnicians', 1)
                ->where('pendingTechnicians.0.name', 'Jamie Rivera')
                ->where('pendingTechnicians.0.status', User::STATUS_PENDING_APPROVAL)
                ->where('pendingTechnicians.0.role', 'Journeyman')
                ->has('activeTechnicians', 1)
                ->where('activeTechnicians.0.name', 'Alex Chen')
                ->has('teamOptions', 1)
                ->where('teamOptions.0.name', $team->name)
                ->where('technicianRoleOptions', TechnicianController::ROLES));
    }

    public function test_the_teams_page_never_lists_a_web_created_foreman_as_a_technician(): void
    {
        $manager = $this->makeManager();
        User::factory()->create(['role' => 'Foreman']);

        $this->actingAs($manager)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                ->has('pendingTechnicians', 0)
                ->has('activeTechnicians', 0));
    }

    public function test_a_non_manager_sees_the_technician_section_but_cannot_approve(): void
    {
        $foreman = User::factory()->create(['role' => 'Foreman']);
        $this->makeMobileTechnician();

        $this->actingAs($foreman)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                ->where('canApproveTechnicians', false)
                ->has('pendingTechnicians', 1));
    }
}
