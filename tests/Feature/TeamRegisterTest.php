<?php

namespace Tests\Feature;

use App\Models\Foreman;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The crew register, read by team.
 *
 * A flat list of names answered "who is free" but never "who is free on the
 * crew already on this site". Teams are the grouping; a person's role on one —
 * foreman, journeyman or apprentice — is what they do there.
 */
class TeamRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $planner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = User::factory()->create(['role' => 'Project Manager']);
    }

    public function test_the_register_is_grouped_by_team(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $south = Team::create(['name' => 'South Crew']);

        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW', 'team_id' => $north->id, 'role' => 'foreman']);
        Foreman::create(['name' => 'Luis Ortega', 'initials' => 'LO', 'team_id' => $north->id, 'role' => 'journeyman']);
        Foreman::create(['name' => 'Robin Ashby', 'initials' => 'RA', 'team_id' => $north->id, 'role' => 'apprentice']);
        Foreman::create(['name' => 'Sam Okafor', 'initials' => 'SO', 'team_id' => $south->id, 'role' => 'journeyman']);

        $this->actingAs($this->planner)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Teams')
                ->has('teams.data', 2)
                ->where('teams.data.0.name', 'North Crew')
                ->has('teams.data.0.members', 3)
                // Foremen first, then journeymen, then apprentices: the order
                // the crew is read in.
                ->where('teams.data.0.members.0.name', 'Dana Wu')
                ->where('teams.data.0.members.0.roleLabel', 'Foreman')
                ->where('teams.data.0.members.1.name', 'Luis Ortega')
                ->where('teams.data.0.members.1.roleLabel', 'Journeyman')
                ->where('teams.data.0.members.2.name', 'Robin Ashby')
                ->where('teams.data.0.members.2.roleLabel', 'Apprentice')
                ->where('teams.data.1.name', 'South Crew')
                ->has('teams.data.1.members', 1));
    }

    /**
     * Nobody is hidden by not having a crew.
     *
     * Everyone added before teams existed has none, and so does anyone hired
     * before their crew is decided. Leaving them off the register would be
     * losing people, not tidying it.
     */
    public function test_someone_on_no_crew_is_still_on_the_register(): void
    {
        Foreman::create(['name' => 'Dana Wu', 'initials' => 'DW']);

        $this->actingAs($this->planner)
            ->get(route('teams.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('teams.data', 0)
                ->has('unassigned', 1)
                ->where('unassigned.0.name', 'Dana Wu')
                // No role was given, so the column reads the default.
                ->where('unassigned.0.roleLabel', 'Journeyman'));
    }

    /** A team is its name, and nothing else is asked for. */
    public function test_a_team_can_be_added(): void
    {
        $this->actingAs($this->planner)
            ->post(route('teams.store'), ['name' => 'North Crew'])
            ->assertRedirect(route('teams.index'))
            ->assertSessionHas('success');

        $this->assertSame('North Crew', Team::sole()->name);
    }

    public function test_a_team_needs_a_name(): void
    {
        $this->actingAs($this->planner)
            ->post(route('teams.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Team::count());
    }

    /** Two crews with one name is a register nobody can read. */
    public function test_a_team_name_already_taken_is_refused(): void
    {
        Team::create(['name' => 'North Crew']);

        $this->actingAs($this->planner)
            ->post(route('teams.store'), ['name' => 'North Crew'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Team::count());
    }

    public function test_a_member_is_added_with_a_role_and_a_crew(): void
    {
        $north = Team::create(['name' => 'North Crew']);

        $this->actingAs($this->planner)
            ->post(route('foremen.store'), [
                'name' => 'Dana Wu',
                'role' => 'apprentice',
                'team_id' => $north->id,
                // Every member added gets a mobile account — see
                // AddMemberMobileAccountTest for that behaviour itself.
                'email' => 'dana@example.com',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.index'));

        $member = Foreman::sole();

        $this->assertSame('apprentice', $member->role);
        $this->assertSame($north->id, $member->team_id);
    }

    /** A role the register does not have is not a role. */
    public function test_a_role_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->planner)
            ->post(route('foremen.store'), ['name' => 'Dana Wu', 'role' => 'manager'])
            ->assertSessionHasErrors('role');

        $this->assertSame(0, Foreman::count());
    }

    public function test_a_member_can_be_moved_between_crews(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $south = Team::create(['name' => 'South Crew']);
        $dana = Foreman::create([
            'name' => 'Dana Wu',
            'initials' => 'DW',
            'team_id' => $north->id,
            'role' => 'foreman',
        ]);

        $this->actingAs($this->planner)
            ->put(route('foremen.update', $dana), [
                'name' => 'Dana Wu',
                'role' => 'foreman',
                'team_id' => $south->id,
            ])
            ->assertSessionHasNoErrors();

        $dana->refresh();

        $this->assertSame($south->id, $dana->team_id);
        $this->assertSame('foreman', $dana->role);
    }

    /**
     * Disbanding a crew does not sack anyone.
     *
     * The people stay on the register with no team — the state the list already
     * has to draw for everyone added before teams existed.
     */
    public function test_deleting_a_team_leaves_its_members_on_the_register(): void
    {
        $north = Team::create(['name' => 'North Crew']);
        $dana = Foreman::create([
            'name' => 'Dana Wu',
            'initials' => 'DW',
            'team_id' => $north->id,
            'role' => 'foreman',
        ]);

        $north->delete();

        $this->assertNotNull($dana->fresh());
        $this->assertNull($dana->fresh()->team_id);
    }

    public function test_the_add_forms_offer_the_crews_and_the_roles(): void
    {
        Team::create(['name' => 'North Crew']);

        $this->actingAs($this->planner)
            ->get(route('foremen.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('ForemanCreate')
                ->has('teams', 1)
                ->where('teams.0.name', 'North Crew')
                ->has('roles', 3)
                ->where('roles.0.value', 'foreman')
                ->where('roles.1.value', 'journeyman')
                ->where('roles.2.value', 'apprentice'));
    }

    /** The old address still lands somewhere: a bookmark is not a dead end. */
    public function test_the_old_register_address_redirects_to_the_teams_screen(): void
    {
        $this->actingAs($this->planner)
            ->get('/foremen')
            ->assertRedirect('/teams');
    }
}
