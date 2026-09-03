<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\AiResult;
use App\Models\Job;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Site / Location field's address lookup, and the coordinates it records.
 *
 * The point is only ever set by picking a suggestion, so the rule these tests
 * hold to is that an address and its coordinates always describe the same
 * place — a record either has both or neither.
 */
class AddressLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config(['services.mapbox.token' => 'pk.test-token']);
    }

    public function test_a_search_returns_each_match_with_its_coordinates(): void
    {
        Http::fake([
            'api.mapbox.com/*' => Http::response([
                'features' => [
                    // Mapbox orders `center` as [longitude, latitude].
                    ['place_name' => '41 Harbor Way, Seattle, WA', 'center' => [-122.3421, 47.6062]],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=41 Harbor Way')
            ->assertOk()
            ->assertExactJson([
                'suggestions' => [[
                    'label' => '41 Harbor Way, Seattle, WA',
                    'latitude' => 47.6062,
                    'longitude' => -122.3421,
                ]],
            ]);
    }

    public function test_a_match_missing_its_point_is_dropped_rather_than_offered(): void
    {
        Http::fake([
            'api.mapbox.com/*' => Http::response([
                'features' => [
                    ['place_name' => 'Somewhere', 'center' => []],
                    ['place_name' => '41 Harbor Way, Seattle, WA', 'center' => [-122.3421, 47.6062]],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor')
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.label', '41 Harbor Way, Seattle, WA');
    }

    public function test_the_field_still_works_when_mapbox_is_unreachable(): void
    {
        Http::fake(['api.mapbox.com/*' => Http::response(status: 500)]);

        // No suggestions, but no error either — the address can still be typed.
        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);
    }

    public function test_nothing_is_asked_of_mapbox_when_no_token_is_configured(): void
    {
        config(['services.mapbox.token' => null]);
        Http::fake();

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);

        Http::assertNothingSent();
    }

    public function test_a_two_character_term_is_not_worth_a_call(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=ha')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);

        Http::assertNothingSent();
    }

    public function test_the_field_is_told_when_the_lookup_is_switched_off(): void
    {
        // Without this the field would just sit there returning nothing, which
        // reads as broken rather than as unconfigured.
        config(['services.mapbox.token' => null]);

        $this->actingAs($this->user)
            ->get('/projects/create')
            ->assertInertia(fn ($page) => $page->where('addressLookupEnabled', false));

        config(['services.mapbox.token' => 'pk.test-token']);

        $this->actingAs($this->user)
            ->get('/projects/create')
            ->assertInertia(fn ($page) => $page->where('addressLookupEnabled', true));
    }

    public function test_the_lookup_is_behind_authentication(): void
    {
        $this->getJson('/address-lookup?q=harbor')->assertUnauthorized();
    }

    public function test_a_client_records_the_point_behind_each_of_its_sites(): void
    {
        $this->actingAs($this->user)->post('/projects', [
            'name' => 'Harborview Data Hall',
            'addresses' => [
                ['label' => 'Main building', 'address' => '41 Harbor Way, Seattle, WA', 'latitude' => 47.6062, 'longitude' => -122.3421],
                ['label' => 'Warehouse', 'address' => '9 Dock Road, Seattle, WA'],
            ],
        ])->assertSessionHasNoErrors();

        $project = Project::sole();
        $sites = $project->addresses;

        $this->assertCount(2, $sites);
        $this->assertSame('47.6062000', $sites[0]->latitude);
        $this->assertSame('-122.3421000', $sites[0]->longitude);
        $this->assertTrue($sites[0]->is_primary);
        // Typed, never matched — so it has an address and no point.
        $this->assertNull($sites[1]->latitude);
        $this->assertFalse($sites[1]->is_primary);

        // The primary is mirrored onto the client for every list that reads it.
        $this->assertSame('41 Harbor Way, Seattle, WA', $project->location);
        $this->assertSame('47.6062000', $project->latitude);
    }

    public function test_a_client_can_be_created_from_an_address_the_lookup_never_matched(): void
    {
        $this->actingAs($this->user)->post('/projects', [
            'name' => 'Rosewood Clinic',
            'addresses' => [['label' => 'Clinic', 'address' => 'Behind the old mill, Route 9']],
        ])->assertSessionHasNoErrors();

        $project = Project::sole();

        $this->assertSame('Behind the old mill, Route 9', $project->location);
        $this->assertNull($project->latitude);
        $this->assertNull($project->longitude);
    }

    public function test_half_a_point_is_rejected(): void
    {
        // A site carrying a latitude without a longitude is not "partly
        // located", it is wrong — so it never reaches the database.
        $this->actingAs($this->user)->post('/projects', [
            'name' => 'Harborview Data Hall',
            'addresses' => [['label' => 'Main hall', 'address' => '41 Harbor Way', 'latitude' => 47.6062]],
        ])->assertSessionHasErrors('addresses.0.longitude');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_a_job_takes_its_address_from_the_site_it_was_given(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);
        $site = $client->addresses()->create([
            'address' => '41 Harbor Way, Seattle, WA',
            'latitude' => 47.6062,
            'longitude' => -122.3421,
            'is_primary' => true,
        ]);

        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Harborview Fit-out',
            'project_id' => $client->id,
            'address_ids' => [$site->id],
            'upload_id' => $this->makeDrawing($client)->id,
        ])->assertSessionHas('success');

        $job = Job::latest('id')->firstOrFail();

        // Snapshot, not a join: the job sheet must not change when the client's
        // address book is later corrected.
        $this->assertSame('41 Harbor Way, Seattle, WA', $job->location);
        $this->assertSame('47.6062000', $job->latitude);
        $this->assertSame('-122.3421000', $job->longitude);
        $this->assertSame([$site->id], $job->addresses->pluck('id')->all());
    }

    public function test_a_job_cannot_be_given_a_site_belonging_to_another_client(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id, 'name' => 'Harborview', 'client' => 'Harborview', 'status' => 'draft',
        ]);
        $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);

        $other = Project::create([
            'user_id' => $this->user->id, 'name' => 'Rosewood', 'client' => 'Rosewood', 'status' => 'draft',
        ]);
        $theirSite = $other->addresses()->create(['address' => '9 Dock Road', 'is_primary' => true]);

        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Harborview Fit-out',
            'project_id' => $client->id,
            'address_ids' => [$theirSite->id],
            'upload_id' => $this->makeDrawing($client)->id,
        ])->assertSessionHasErrors('address_ids');

        $this->assertSame(0, Job::count());
    }

    public function test_a_site_can_be_added_to_a_client_from_the_job_screen(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            // Mirrors the primary site below, as the client screen writes it.
            'location' => '41 Harbor Way',
            'status' => 'draft',
        ]);
        $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true, 'position' => 0]);

        $this->actingAs($this->user)
            ->from('/jobs/create')
            ->post(route('projects.addresses.store', $client), [
                'label' => 'Warehouse',
                'address' => '9 Dock Road, Seattle, WA',
                'latitude' => 47.6062,
                'longitude' => -122.3421,
            ])
            ->assertRedirect('/jobs/create')
            ->assertSessionHas('success');

        $added = $client->addresses()->reorder()->latest('id')->first();

        $this->assertSame('9 Dock Road, Seattle, WA', $added->address);
        $this->assertSame('Warehouse', $added->label);
        // The client already had a primary, so this one does not take it over.
        $this->assertFalse($added->is_primary);
        $this->assertSame('41 Harbor Way', $client->refresh()->location);
    }

    public function test_the_first_site_added_becomes_the_clients_primary(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Rosewood Clinic',
            'client' => 'Rosewood Clinic',
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->post(route('projects.addresses.store', $client), [
                'label' => 'Dock building',
                'address' => '9 Dock Road',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($client->addresses()->sole()->is_primary);
        // Mirrored onto the client, which is what every list reads.
        $this->assertSame('9 Dock Road', $client->refresh()->location);
    }

    public function test_a_job_runs_at_one_site(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);
        $first = $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true]);
        $second = $client->addresses()->create(['address' => '9 Dock Road', 'position' => 1]);

        // The screen offers radios; the rule has to agree with it, or a
        // hand-made request could still put a job at two addresses.
        $this->actingAs($this->user)->post('/jobs', [
            'name' => 'Harborview Fit-out',
            'project_id' => $client->id,
            'address_ids' => [$first->id, $second->id],
            'upload_id' => $this->makeDrawing($client)->id,
        ])->assertSessionHasErrors('address_ids');

        $this->assertSame(0, Job::count());
    }

    public function test_a_site_has_to_be_named(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Rosewood Clinic',
            'client' => 'Rosewood Clinic',
            'status' => 'draft',
        ]);

        // A client with three sites is read by the names people call them,
        // and "9 Dock Road" is not one of those names.
        $this->actingAs($this->user)
            ->post(route('projects.addresses.store', $client), ['address' => '9 Dock Road'])
            ->assertSessionHasErrors('label');

        $this->assertSame(0, $client->addresses()->count());
    }

    public function test_a_site_cannot_be_added_to_someone_elses_client(): void
    {
        $theirs = User::factory()->create()->projects()->create([
            'name' => 'Someone Else Tower', 'client' => 'Someone Else Tower', 'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->post(route('projects.addresses.store', $theirs), [
                'label' => 'Dock building',
                'address' => '9 Dock Road',
            ])
            ->assertForbidden();

        $this->assertSame(0, $theirs->addresses()->count());
    }

    public function test_the_review_summary_offers_the_client_register_defaulting_to_the_takeoffs(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'project_type' => 'commercial',
            'status' => 'completed',
        ]);
        $client->addresses()->create(['address' => '41 Harbor Way', 'is_primary' => true, 'position' => 0]);
        $client->addresses()->create(['label' => 'Warehouse', 'address' => '9 Dock Road', 'position' => 1]);

        $aiJob = AiJob::create([
            'project_id' => $client->id, 'user_id' => $this->user->id, 'status' => 'completed',
        ]);
        $result = AiResult::create([
            'ai_job_id' => $aiJob->id, 'project_id' => $client->id, 'original_payload' => [],
        ]);

        // A second client, so the register is more than the takeoff's own.
        $other = Project::create([
            'user_id' => $this->user->id, 'name' => 'Rosewood Clinic',
            'client' => 'Rosewood Clinic', 'status' => 'draft',
        ]);
        $other->addresses()->create(['address' => '2 Rose Lane', 'is_primary' => true]);

        /*
         * The whole register is offered, with the takeoff's own client as where
         * the form starts — work is sometimes taken off one client's drawing
         * and built for another.
         */
        $this->actingAs($this->user)
            ->get(route('finals.show', $result))
            ->assertInertia(fn ($page) => $page
                ->component('FinalSymbols')
                ->where('defaultClientId', $client->id)
                ->has('clients', 2)
                ->where('clients.0.name', 'Harborview Data Hall')
                ->has('clients.0.addresses', 2)
                ->where('clients.0.addresses.0.isPrimary', true)
                ->where('clients.0.addresses.1.display', 'Warehouse — 9 Dock Road')
                // Recorded on the client, so the job form fills it in rather
                // than asking the same question twice.
                ->where('clients.0.projectType', 'commercial'));
    }

    public function test_the_create_job_screen_offers_every_site_a_client_has(): void
    {
        $client = Project::create([
            'user_id' => $this->user->id,
            'name' => 'Harborview Data Hall',
            'client' => 'Harborview Data Hall',
            'status' => 'draft',
        ]);
        $client->addresses()->create([
            'address' => '41 Harbor Way, Seattle, WA', 'is_primary' => true, 'position' => 0,
        ]);
        $client->addresses()->create([
            'label' => 'Warehouse', 'address' => '9 Dock Road', 'is_primary' => false, 'position' => 1,
        ]);

        $this->actingAs($this->user)
            ->get('/jobs/create')
            ->assertInertia(fn ($page) => $page
                ->has('clients.0.addresses', 2)
                ->where('clients.0.addresses.0.address', '41 Harbor Way, Seattle, WA')
                ->where('clients.0.addresses.0.isPrimary', true)
                // Label and address as one line, so a list needs no formatting.
                ->where('clients.0.addresses.1.display', 'Warehouse — 9 Dock Road'));
    }

    /** A drawing on the client's record — a job is raised against one. */
    private function makeDrawing(Project $client): Upload
    {
        return $client->uploads()->create([
            'user_id' => $this->user->id,
            'name' => 'E-101.pdf',
            'format' => 'PDF',
            'size_bytes' => 1024,
            'status' => 'completed',
        ]);
    }
}
