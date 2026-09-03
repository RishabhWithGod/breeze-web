<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding a site to a client without leaving the screen that needed it.
 *
 * Raising a job for an address the client's book does not have yet is normal —
 * the address arrives with the job, not before it.
 */
class ClientSiteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'Project Manager']);
        $this->client = $this->user->clients()->create(['name' => 'Harborview']);
    }

    public function test_a_site_is_added_to_the_clients_book(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [
                'label' => 'Warehouse',
                'address' => '9 Dock Road',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $site = $this->client->addresses()->sole();

        $this->assertSame('Warehouse', $site->label);
        $this->assertSame('9 Dock Road', $site->address);
        // The first site a client gets is the one everything defaults to.
        $this->assertTrue($site->is_primary);
    }

    public function test_a_second_site_does_not_become_the_primary(): void
    {
        $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbor Way', 'is_primary' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [
                'label' => 'Warehouse',
                'address' => '9 Dock Road',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'Main',
            $this->client->addresses()->where('is_primary', true)->sole()->label,
        );
    }

    public function test_a_site_needs_a_name_and_an_address(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [])
            ->assertSessionHasErrors(['label', 'address']);

        $this->assertSame(0, $this->client->addresses()->count());
    }

    public function test_a_site_can_be_removed_from_the_book(): void
    {
        $site = $this->client->addresses()->create([
            'label' => 'Warehouse', 'address' => '9 Dock Road',
        ]);

        $this->actingAs($this->user)
            ->delete(route('clients.addresses.destroy', [$this->client, $site]))
            ->assertSessionHas('warning');

        $this->assertSame(0, $this->client->addresses()->count());
    }

    public function test_removing_the_primary_promotes_the_next_site(): void
    {
        $primary = $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbor Way', 'is_primary' => true, 'position' => 0,
        ]);
        $second = $this->client->addresses()->create([
            'label' => 'Warehouse', 'address' => '9 Dock Road', 'position' => 1,
        ]);

        $this->actingAs($this->user)
            ->delete(route('clients.addresses.destroy', [$this->client, $primary]));

        // "The site everything defaults to" has to be a site that exists.
        $this->assertTrue($second->refresh()->is_primary);
    }

    public function test_a_site_a_job_is_standing_on_is_kept(): void
    {
        $site = $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbor Way', 'is_primary' => true,
        ]);

        $job = Job::create([
            'name' => 'Harborview Fit-out',
            'client' => 'Harborview',
            'location' => '41 Harbor Way',
            'status' => 'planning',
        ]);
        $job->addresses()->attach($site->id, ['position' => 0]);

        /*
         * `job_addresses` cascades, so the delete would go through and quietly
         * take the job's site with it.
         */
        $this->actingAs($this->user)
            ->delete(route('clients.addresses.destroy', [$this->client, $site]))
            ->assertSessionHas('warning');

        $this->assertSame(1, $this->client->addresses()->count());
        $this->assertSame(1, $job->addresses()->count());
    }

    public function test_the_screen_says_how_much_work_is_on_each_site(): void
    {
        $site = $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbor Way', 'is_primary' => true,
        ]);

        $job = Job::create([
            'name' => 'Harborview Fit-out', 'client' => 'Harborview',
            'location' => '41 Harbor Way', 'status' => 'planning',
        ]);
        $job->addresses()->attach($site->id, ['position' => 0]);

        // The count is what greys the button out, so the reason is beside it
        // rather than arriving as an alert after the click.
        $this->actingAs($this->user)
            ->get(route('clients.show', $this->client))
            ->assertInertia(fn ($page) => $page
                ->where('client.addresses.0.jobCount', 1));
    }

    public function test_a_site_on_someone_elses_client_is_not_removable(): void
    {
        $theirs = User::factory()->create()->clients()->create(['name' => 'Someone Else']);
        $site = $theirs->addresses()->create(['label' => 'Main', 'address' => '9 Dock Road']);

        $this->actingAs($this->user)
            ->delete(route('clients.addresses.destroy', [$theirs, $site]))
            ->assertForbidden();

        $this->assertSame(1, $theirs->addresses()->count());
    }

    public function test_a_site_cannot_be_added_to_someone_elses_client(): void
    {
        $theirs = User::factory()->create()->clients()->create(['name' => 'Someone Else']);

        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $theirs), [
                'label' => 'Main', 'address' => '9 Dock Road',
            ])
            ->assertForbidden();

        $this->assertSame(0, $theirs->addresses()->count());
    }
}
