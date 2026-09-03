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
                'address' => '9 Dock Road, Seattle, WA 98134, USA',
                'latitude' => 47.5801,
                'longitude' => -122.33,
                'place_id' => 'ChIJdock',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $site = $this->client->addresses()->sole();

        $this->assertSame('Warehouse', $site->label);
        $this->assertSame('9 Dock Road, Seattle, WA 98134, USA', $site->address);
        // The three location values travel together — a stored point always
        // belongs to the address stored beside it.
        $this->assertSame('47.5801000', $site->latitude);
        $this->assertSame('ChIJdock', $site->place_id);
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

    public function test_correcting_a_site_corrects_it_on_the_work_standing_on_it(): void
    {
        $site = $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbour Way', 'is_primary' => true,
        ]);

        $project = $this->user->projects()->create([
            'client_id' => $this->client->id, 'name' => 'Phase 1',
            'client' => 'Harborview', 'location' => '41 Harbour Way', 'status' => 'draft',
        ]);

        $job = Job::create([
            'name' => 'Harborview Fit-out', 'client' => 'Harborview',
            'location' => '41 Harbour Way', 'status' => 'planning',
        ]);
        $job->addresses()->attach($site->id, ['position' => 0]);

        $this->actingAs($this->user)
            ->put(route('clients.addresses.update', [$this->client, $site]), [
                'label' => 'Main building',
                'address' => '41 Harbor Way',
                'latitude' => 47.6062,
                'longitude' => -122.3421,
                'place_id' => 'ChIJcorrected',
            ])
            ->assertSessionHas('success');

        // A job and a project each keep a snapshot rather than reading through
        // the book, so fixing a typo has to fix those too.
        $this->assertSame('41 Harbor Way', $job->refresh()->location);
        $this->assertSame('47.6062000', $job->latitude);
        $this->assertSame('ChIJcorrected', $job->place_id);
        $this->assertSame('41 Harbor Way', $project->refresh()->location);
        $this->assertSame('ChIJcorrected', $project->place_id);
        $this->assertSame('Main building', $site->refresh()->label);
    }

    public function test_a_correction_leaves_other_clients_work_alone(): void
    {
        $mine = $this->client->addresses()->create([
            'label' => 'Main', 'address' => '41 Harbour Way', 'is_primary' => true,
        ]);

        // Same spelling, different client. Matching on text alone would drag
        // this one along with it.
        $theirClient = $this->user->clients()->create(['name' => 'Someone Else']);
        $theirProject = $this->user->projects()->create([
            'client_id' => $theirClient->id, 'name' => 'Theirs',
            'client' => 'Someone Else', 'location' => '41 Harbour Way', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.addresses.update', [$this->client, $mine]), [
                'label' => 'Main', 'address' => '41 Harbor Way',
            ]);

        $this->assertSame('41 Harbour Way', $theirProject->refresh()->location);
    }

    public function test_a_site_cannot_be_edited_on_someone_elses_client(): void
    {
        $theirs = User::factory()->create()->clients()->create(['name' => 'Someone Else']);
        $site = $theirs->addresses()->create(['label' => 'Main', 'address' => '9 Dock Road']);

        $this->actingAs($this->user)
            ->put(route('clients.addresses.update', [$theirs, $site]), [
                'label' => 'Hijacked', 'address' => 'Somewhere else',
            ])
            ->assertForbidden();

        $this->assertSame('Main', $site->refresh()->label);
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

    /*
     * The kind of building a site is.
     *
     * It belongs to the address, not to the client: one client can own a house
     * and a warehouse, and it is the building that decides how the work is
     * priced. A job raised at a site starts from this answer, which is the only
     * reason the question is worth asking here.
     */

    public function test_a_site_records_what_kind_of_building_it_is(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [
                'label' => 'Warehouse',
                'address' => '9 Dock Road',
                'site_type' => 'industrial',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('industrial', $this->client->addresses()->sole()->site_type);
    }

    public function test_a_site_may_have_no_type_yet(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [
                'label' => 'Yard',
                'address' => '1 Back Lane',
            ])
            ->assertSessionHasNoErrors();

        // A site can go on the book before anyone has been to it. Guessing
        // would be worse than leaving the question for the job that goes there.
        $this->assertNull($this->client->addresses()->sole()->site_type);
    }

    public function test_a_type_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.addresses.store', $this->client), [
                'label' => 'Yard',
                'address' => '1 Back Lane',
                'site_type' => 'nuclear',
            ])
            ->assertSessionHasErrors('site_type');

        $this->assertSame(0, $this->client->addresses()->count());
    }

    public function test_a_client_created_with_sites_keeps_each_ones_type(): void
    {
        $this->actingAs($this->user)
            ->post(route('clients.store'), [
                'name' => 'Northgate Holdings',
                'addresses' => [
                    ['label' => 'Depot', 'address' => '4 Mill Way', 'site_type' => 'industrial'],
                    ['label' => 'Head office', 'address' => '2 King St', 'site_type' => 'commercial'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $sites = Client::where('name', 'Northgate Holdings')->sole()
            ->addresses()->orderBy('position')->pluck('site_type', 'label');

        // Each site answers for itself — the second is not the first's type.
        $this->assertSame('industrial', $sites['Depot']);
        $this->assertSame('commercial', $sites['Head office']);
    }

    public function test_correcting_a_sites_type_is_what_the_next_job_starts_from(): void
    {
        $site = $this->client->addresses()->create([
            'label' => 'Unit 4',
            'address' => '4 Mill Way',
            'site_type' => 'residential',
            'is_primary' => true,
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->put(route('clients.addresses.update', [$this->client, $site]), [
                'label' => 'Unit 4',
                'address' => '4 Mill Way',
                'site_type' => 'commercial',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('commercial', $site->fresh()->site_type);
    }

    public function test_the_create_job_screen_carries_each_sites_type(): void
    {
        $this->client->addresses()->create([
            'label' => 'Warehouse',
            'address' => '9 Dock Road',
            'site_type' => 'industrial',
            'is_primary' => true,
            'position' => 0,
        ]);

        // The form fills the job's own type from the site the moment one is
        // picked, so the type has to reach the screen with the site.
        $this->actingAs($this->user)
            ->get(route('jobs.create'))
            ->assertInertia(function ($page) {
                $client = collect($page->toArray()['props']['clients'])
                    ->firstWhere('id', $this->client->id);

                $this->assertSame('industrial', $client['addresses'][0]['siteType']);
            });
    }
}
