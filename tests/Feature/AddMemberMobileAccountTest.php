<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Add Member, with a password: the web register and the mobile app's own
 * account are the same thing.
 *
 * A manager filling this form in has already vouched for whoever they are
 * adding — so unlike a self-registered mobile signup, there is no approval
 * step to wait through. The account this creates has to be indistinguishable
 * from one `TechnicianController::approve()` produced: same login path
 * (`LoginRequest::authenticate()`), same `active`/`web` status, same crew
 * links — not a second, parallel way of getting into the app.
 */
class AddMemberMobileAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_adding_a_member_with_a_password_creates_an_active_mobile_account(): void
    {
        $north = Team::create(['name' => 'North Crew']);

        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'team_id' => $north->id,
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.index'));

        $foreman = Foreman::sole();
        $user = User::where('email', 'dana@example.com')->sole();

        // Same register entry as a password-less Add Member, now linked to
        // a real account rather than left as a name with nobody behind it.
        $this->assertSame($user->id, $foreman->user_id);
        $this->assertSame('journeyman', $foreman->role);
        $this->assertSame($north->id, $foreman->team_id);

        // Already approved — no `pending_approval`/`mobile` a self-signup
        // would start at.
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertSame(User::SOURCE_WEB, $user->registration_source);
        $this->assertNull($user->approved_at);
        // The account side spells the same role capitalised — see
        // `TechnicianController::ROLES`.
        $this->assertSame('Journeyman', $user->role);

        // Hashed, never stored as typed.
        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));

        // The same crew-record Time Tracking would otherwise resolve on
        // first use, created up front with the same team.
        $teamMember = TeamMember::where('user_id', $user->id)->sole();
        $this->assertSame($north->id, $teamMember->team_id);
    }

    public function test_the_new_account_can_log_into_the_mobile_app_immediately(): void
    {
        $this->actingAs($this->planner)->post(route('foremen.store'), [
            'name' => 'Dana Wu',
            'role' => 'foreman',
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'Foreman')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);

        $user = User::where('email', 'dana@example.com')->sole();
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    /** Role, team and job access all load correctly straight after login — nothing left half set up. */
    public function test_role_team_and_job_access_load_correctly_after_login(): void
    {
        $north = Team::create(['name' => 'North Crew']);

        $this->actingAs($this->planner)->post(route('foremen.store'), [
            'name' => 'Dana Wu',
            'role' => 'apprentice',
            'team_id' => $north->id,
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        // Sanctum's guard checks the session-authenticated user before a
        // bearer token — without this, the calls below would silently
        // authenticate as the still-"logged in" planner instead of the
        // member whose token this test is actually exercising.
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'Apprentice');

        // Behind `account.active` — refused for a pending account, open for
        // this one because it never was.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/jobs')
            ->assertOk();
    }

    /** Every member added gets a mobile account, so a password is not optional. */
    public function test_adding_a_member_without_a_password_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Foreman::count());
        $this->assertDatabaseMissing('users', ['email' => 'dana@example.com']);
    }

    /** And a password needs a real email to actually sign in with. */
    public function test_adding_a_member_without_an_email_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Foreman::count());
        $this->assertDatabaseMissing('users', ['email' => 'dana@example.com']);
    }

    /** Left blank, joining date defaults to the day the member is actually added. */
    public function test_joining_date_defaults_to_today_when_left_blank(): void
    {
        $this->travelTo(now()->setTime(12, 0));

        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(now()->toDateString(), Foreman::sole()->started_on->toDateString());
    }

    /** An explicit date is still honoured — the default only fills a genuine gap. */
    public function test_an_explicit_joining_date_is_kept(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
                'started_on' => '2026-01-15',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-01-15', Foreman::sole()->started_on->toDateString());
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        User::factory()->create(['email' => 'dana@example.com']);

        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('email');

        // Refused before anything was written — no orphaned register entry
        // left behind by the half of the request that would have succeeded.
        $this->assertSame(0, Foreman::count());
    }

    public function test_a_weak_password_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Foreman::count());
        $this->assertDatabaseMissing('users', ['email' => 'dana@example.com']);
    }

    public function test_a_mismatched_password_confirmation_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'journeyman',
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'something-else-entirely',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'dana@example.com']);
    }

    /** Reuses the same login path — a wrong password is refused exactly as it would be for any other account. */
    public function test_wrong_login_credentials_are_rejected(): void
    {
        $this->actingAs($this->planner)->post(route('foremen.store'), [
            'name' => 'Dana Wu',
            'role' => 'journeyman',
            'email' => 'dana@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dana@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $user = User::where('email', 'dana@example.com')->sole();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }
}
