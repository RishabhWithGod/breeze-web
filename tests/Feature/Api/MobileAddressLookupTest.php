<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Mobile Site Location: the Google Places proxy (`Api\V1\AddressLookupController`,
 * mirroring web's own `AddressLookupController`/`GooglePlaces`) and the
 * client address book CRUD (`Api\V1\ClientAddressController`, mirroring
 * web's own) the Continue-to-Job form's "Add location" flow uses.
 */
class MobileAddressLookupTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_places.key' => 'test-key']);
    }

    // --- address lookup -------------------------------------------------

    public function test_typing_returns_suggestions_with_the_id_needed_to_resolve_them(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [[
                    'placePrediction' => [
                        'placeId' => 'ChIJtest123',
                        'text' => ['text' => '41 Harbor Way, Seattle, WA, USA'],
                        'structuredFormat' => [
                            'mainText' => ['text' => '41 Harbor Way'],
                            'secondaryText' => ['text' => 'Seattle, WA, USA'],
                        ],
                    ],
                ]],
            ]),
        ]);
        $user = User::factory()->create(['role' => 'Project Manager']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/address-lookup?q=41 Harbor Way&session=abc-123')
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.placeId', 'ChIJtest123')
            ->assertJsonPath('data.suggestions.0.primary', '41 Harbor Way');
    }

    public function test_lookup_requires_authentication(): void
    {
        $this->getJson('/api/v1/address-lookup?q=harbor&session=abc')->assertUnauthorized();
        $this->getJson('/api/v1/address-lookup/place?place_id=x&session=abc')->assertUnauthorized();
    }

    public function test_picking_a_place_returns_the_address_and_its_point(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places/ChIJtest123*' => Http::response([
                'id' => 'ChIJtest123',
                'formattedAddress' => '41 Harbor Way, Seattle, WA 98101, USA',
                'location' => ['latitude' => 47.6062, 'longitude' => -122.3421],
                'addressComponents' => [
                    ['longText' => 'Seattle', 'types' => ['locality']],
                ],
            ]),
        ]);
        $user = User::factory()->create(['role' => 'Project Manager']);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/address-lookup/place?place_id=ChIJtest123&session=abc')
            ->assertOk()
            ->assertJsonPath('data.place.address', '41 Harbor Way, Seattle, WA 98101, USA')
            ->assertJsonPath('data.place.latitude', 47.6062);
    }

    public function test_the_key_never_reaches_the_response(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => []])]);
        $user = User::factory()->create(['role' => 'Project Manager']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/address-lookup?q=harbor way&session=abc');

        $this->assertStringNotContainsString('test-key', $response->getContent());
    }

    // --- client address CRUD ---------------------------------------------

    private function makeClient(User $owner): Client
    {
        return Client::create(['user_id' => $owner->id, 'name' => 'Harborview LLC']);
    }

    public function test_a_manager_can_add_a_site_from_a_picked_place(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/clients/{$client->id}/addresses", [
                'label' => 'Main building',
                'address' => '41 Harbor Way, Seattle, WA 98101, USA',
                'site_type' => 'commercial',
                'latitude' => 47.6062,
                'longitude' => -122.3421,
                'place_id' => 'ChIJtest123',
            ])
            ->assertCreated();

        $this->assertTrue($response->json('data.isPrimary'));
        $this->assertDatabaseHas('client_addresses', [
            'client_id' => $client->id,
            'label' => 'Main building',
            'place_id' => 'ChIJtest123',
            'is_primary' => true,
        ]);
    }

    public function test_a_site_typed_without_a_picked_place_has_no_point(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/clients/{$client->id}/addresses", [
                'label' => 'Clinic',
                'address' => 'Behind the old mill, Route 9',
            ])
            ->assertCreated();

        $site = $client->addresses()->sole();
        $this->assertNull($site->latitude);
        $this->assertNull($site->place_id);
    }

    public function test_half_a_point_is_rejected(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->postJson("/api/v1/clients/{$client->id}/addresses", [
                'label' => 'Main hall',
                'address' => '41 Harbor Way',
                'latitude' => 47.6062,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $client->addresses()->count());
    }

    public function test_add_address_is_forbidden_for_a_non_owner(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $other = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($other))
            ->postJson("/api/v1/clients/{$client->id}/addresses", [
                'label' => 'Main', 'address' => '41 Harbor Way',
            ])
            ->assertForbidden();
    }

    public function test_a_manager_can_update_a_site(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);
        $address = $client->addresses()->create(['label' => 'Main', 'address' => 'Old address', 'position' => 0, 'is_primary' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/clients/{$client->id}/addresses/{$address->id}", [
                'label' => 'Main HQ',
                'address' => 'New address, Seattle, WA',
            ])
            ->assertOk();

        $this->assertDatabaseHas('client_addresses', ['id' => $address->id, 'label' => 'Main HQ', 'address' => 'New address, Seattle, WA']);
    }

    public function test_a_manager_can_delete_a_site_with_no_jobs_on_it(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);
        $address = $client->addresses()->create(['label' => 'Main', 'address' => 'A', 'position' => 0, 'is_primary' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->deleteJson("/api/v1/clients/{$client->id}/addresses/{$address->id}")
            ->assertOk();

        $this->assertDatabaseMissing('client_addresses', ['id' => $address->id]);
    }

    public function test_an_address_from_another_client_404s(): void
    {
        $owner = User::factory()->create(['role' => 'Project Manager']);
        $client = $this->makeClient($owner);
        $otherClient = Client::create(['user_id' => $owner->id, 'name' => 'Other']);
        $address = $otherClient->addresses()->create(['label' => 'Main', 'address' => 'A', 'position' => 0, 'is_primary' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($owner))
            ->putJson("/api/v1/clients/{$client->id}/addresses/{$address->id}", [
                'label' => 'Main', 'address' => 'A',
            ])
            ->assertNotFound();
    }
}
