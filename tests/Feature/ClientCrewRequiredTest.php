<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Foreman;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Adding a client needs a team and at least one member; a member can be added
 * without a team and picks one up when they are put on a client.
 */
class ClientCrewRequiredTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $north;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->north = Team::create(['name' => 'North Crew']);
    }

    private function member(string $name, ?int $teamId): Foreman
    {
        return Foreman::create(['name' => $name, 'initials' => 'XX', 'role' => 'journeyman', 'team_id' => $teamId]);
    }

    public function test_a_client_needs_a_team_and_at_least_one_member(): void
    {
        $this->actingAs($this->user)->post(route('clients.store'), ['name' => 'Harborview'])
            ->assertSessionHasErrors(['team_id', 'member_ids']);

        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview', 'team_id' => $this->north->id, 'member_ids' => [],
        ])->assertSessionHasErrors('member_ids');

        $this->assertSame(0, Client::count());
    }

    public function test_the_create_screen_offers_the_register_with_each_persons_team(): void
    {
        $this->member('Dana', $this->north->id);
        $this->member('Newcomer', null);

        $this->actingAs($this->user)->get(route('clients.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClientCreate')
                ->has('members', 2)
                ->where('members.0.name', 'Dana')
                ->where('members.0.teamId', $this->north->id)
                ->where('members.1.teamId', null));
    }

    public function test_a_member_with_no_team_joins_the_clients_team_when_picked(): void
    {
        $onTeam = $this->member('Dana', $this->north->id);
        $teamless = $this->member('Newcomer', null);

        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview', 'team_id' => $this->north->id, 'member_ids' => [$onTeam->id, $teamless->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->north->id, Client::where('name', 'Harborview')->sole()->team_id);
        $this->assertSame($this->north->id, $teamless->fresh()->team_id);
        $this->assertSame($this->north->id, $onTeam->fresh()->team_id);
    }

    public function test_someone_on_another_team_cannot_be_put_on_the_clients_team(): void
    {
        $south = Team::create(['name' => 'South Crew']);
        $elsewhere = $this->member('Sam', $south->id);

        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview', 'team_id' => $this->north->id, 'member_ids' => [$elsewhere->id],
        ])->assertSessionHasErrors('member_ids');

        $this->assertSame(0, Client::count());
        $this->assertSame($south->id, $elsewhere->fresh()->team_id);
    }

    public function test_a_member_can_be_added_without_a_team(): void
    {
        $this->actingAs($this->user)->post(route('foremen.store'), [
            'name' => 'Robin Ashby',
            'role' => 'apprentice',
            'team_id' => null,
            'email' => 'robin@example.com',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
            'inline' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull(Foreman::where('name', 'Robin Ashby')->sole()->team_id);
    }
}
