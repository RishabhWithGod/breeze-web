<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Site / Location field's address lookup — Google Places (New).
 *
 * Two calls and only two: Autocomplete while typing, Place Details once when
 * somebody picks. The rule these tests hold to is that an address and its
 * coordinates always describe the same place — a record has both or neither —
 * and that the API key never leaves the server.
 */
class AddressLookupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['services.google_places.key' => 'test-key']);
    }

    /* ------------------------------------------------------ autocomplete -- */

    public function test_typing_returns_each_match_with_the_id_needed_to_resolve_it(): void
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

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=41 Harbor Way&session=abc-123')
            ->assertOk()
            ->assertExactJson([
                'suggestions' => [[
                    'placeId' => 'ChIJtest123',
                    'label' => '41 Harbor Way, Seattle, WA, USA',
                    'primary' => '41 Harbor Way',
                    'secondary' => 'Seattle, WA, USA',
                ]],
            ]);
    }

    public function test_the_session_token_reaches_google_so_a_search_bills_as_one(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => []])]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor way&session=session-token-1')
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request['sessionToken'] === 'session-token-1'
                // Only the fields the list draws — autocomplete bills by mask.
                && str_contains($request->header('X-Goog-FieldMask')[0], 'placePrediction.placeId');
        });
    }

    public function test_a_prediction_with_no_id_is_dropped_rather_than_offered(): void
    {
        // Unresolvable later, so not something anyone can usefully pick.
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'suggestions' => [
                    ['placePrediction' => ['text' => ['text' => 'Somewhere']]],
                    ['placePrediction' => [
                        'placeId' => 'ChIJgood',
                        'text' => ['text' => '9 Dock Road'],
                    ]],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=dock road&session=abc')
            ->assertOk()
            ->assertJsonCount(1, 'suggestions')
            ->assertJsonPath('suggestions.0.placeId', 'ChIJgood');
    }

    public function test_a_two_character_term_is_not_worth_a_call(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=ha&session=abc')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);

        Http::assertNothingSent();
    }

    public function test_nothing_is_asked_of_google_when_no_key_is_configured(): void
    {
        config(['services.google_places.key' => null]);
        Http::fake();

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor way&session=abc')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);

        Http::assertNothingSent();
    }

    public function test_the_field_still_works_when_google_is_unreachable(): void
    {
        // Billing off, key rejected, quota gone, network down: all the same
        // answer. The field falls back to an ordinary text box.
        Http::fake(['places.googleapis.com/*' => Http::response(status: 403)]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor way&session=abc')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);
    }

    public function test_a_search_without_a_session_is_refused(): void
    {
        $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor way')
            ->assertJsonValidationErrors('session');
    }

    /* ----------------------------------------------------- place details -- */

    public function test_picking_a_place_returns_the_address_and_its_point(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places/ChIJtest123*' => Http::response([
                'id' => 'ChIJtest123',
                'formattedAddress' => '41 Harbor Way, Seattle, WA 98101, USA',
                'location' => ['latitude' => 47.6062, 'longitude' => -122.3421],
                'addressComponents' => [
                    ['longText' => 'Seattle', 'types' => ['locality']],
                    ['longText' => 'Washington', 'types' => ['administrative_area_level_1']],
                    ['longText' => '98101', 'types' => ['postal_code']],
                    ['longText' => 'United States', 'types' => ['country']],
                ],
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup/place?place_id=ChIJtest123&session=abc')
            ->assertOk()
            ->assertExactJson([
                'place' => [
                    'placeId' => 'ChIJtest123',
                    'address' => '41 Harbor Way, Seattle, WA 98101, USA',
                    'latitude' => 47.6062,
                    'longitude' => -122.3421,
                    'components' => [
                        'city' => 'Seattle',
                        'region' => 'Washington',
                        'postcode' => '98101',
                        'country' => 'United States',
                    ],
                ],
            ]);
    }

    public function test_a_place_missing_its_point_is_answered_as_no_place(): void
    {
        // Half a place is worse than none: it would be stored as located when
        // it is not.
        Http::fake([
            'places.googleapis.com/*' => Http::response([
                'id' => 'ChIJtest123',
                'formattedAddress' => 'Somewhere',
            ]),
        ]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup/place?place_id=ChIJtest123&session=abc')
            ->assertOk()
            ->assertExactJson(['place' => null]);
    }

    public function test_a_details_failure_is_answered_as_no_place(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(status: 500)]);

        $this->actingAs($this->user)
            ->getJson('/address-lookup/place?place_id=ChIJtest123&session=abc')
            ->assertOk()
            ->assertExactJson(['place' => null]);
    }

    /* ------------------------------------------------------------ access -- */

    public function test_the_field_is_told_when_the_lookup_is_switched_off(): void
    {
        // Without this the field would just sit there returning nothing, which
        // reads as broken rather than as unconfigured.
        config(['services.google_places.key' => null]);

        $this->actingAs($this->user)
            ->get(route('clients.create'))
            ->assertInertia(fn ($page) => $page->where('addressLookupEnabled', false));

        config(['services.google_places.key' => 'test-key']);

        $this->actingAs($this->user)
            ->get(route('clients.create'))
            ->assertInertia(fn ($page) => $page->where('addressLookupEnabled', true));
    }

    public function test_the_lookup_is_behind_authentication(): void
    {
        $this->getJson('/address-lookup?q=harbor&session=abc')->assertUnauthorized();
        $this->getJson('/address-lookup/place?place_id=x&session=abc')->assertUnauthorized();
    }

    public function test_the_key_never_reaches_the_browser(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['suggestions' => []])]);

        $suggest = $this->actingAs($this->user)
            ->getJson('/address-lookup?q=harbor way&session=abc');

        // Only whether a lookup is possible, never the key that makes it so.
        $this->assertStringNotContainsString('test-key', $suggest->getContent());

        $page = $this->actingAs($this->user)->get(route('clients.create'));

        $this->assertStringNotContainsString('test-key', $page->getContent());
    }

    /* --------------------------------------------------------- persisted -- */

    public function test_a_client_records_the_place_behind_each_of_its_sites(): void
    {
        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview Data Hall',
            'addresses' => [
                [
                    'label' => 'Main building',
                    'address' => '41 Harbor Way, Seattle, WA 98101, USA',
                    'latitude' => 47.6062,
                    'longitude' => -122.3421,
                    'place_id' => 'ChIJtest123',
                ],
                ['label' => 'Warehouse', 'address' => '9 Dock Road, Seattle, WA'],
            ],
        ])->assertSessionHasNoErrors();

        $sites = Client::sole()->addresses()->orderBy('position')->get();

        $this->assertCount(2, $sites);
        $this->assertSame('47.6062000', $sites[0]->latitude);
        $this->assertSame('-122.3421000', $sites[0]->longitude);
        $this->assertSame('ChIJtest123', $sites[0]->place_id);
        $this->assertTrue($sites[0]->is_primary);

        // Typed, never chosen — so it has an address and no place.
        $this->assertNull($sites[1]->latitude);
        $this->assertNull($sites[1]->place_id);
    }

    public function test_a_client_can_be_created_from_an_address_google_never_matched(): void
    {
        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Rosewood Clinic',
            'addresses' => [['label' => 'Clinic', 'address' => 'Behind the old mill, Route 9']],
        ])->assertSessionHasNoErrors();

        $site = Client::sole()->addresses()->sole();

        $this->assertSame('Behind the old mill, Route 9', $site->address);
        $this->assertNull($site->latitude);
        $this->assertNull($site->place_id);
    }

    public function test_half_a_point_is_rejected(): void
    {
        // A site carrying a latitude without a longitude is not "partly
        // located", it is wrong — so it never reaches the database.
        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview Data Hall',
            'addresses' => [[
                'label' => 'Main hall', 'address' => '41 Harbor Way', 'latitude' => 47.6062,
            ]],
        ])->assertSessionHasErrors('addresses.0.longitude');

        $this->assertSame(0, Client::count());
    }

    public function test_coordinates_off_the_globe_are_refused(): void
    {
        // The browser is not authoritative about anything, least of all this.
        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Nowhere',
            'addresses' => [[
                'label' => 'Main', 'address' => 'Nowhere',
                'latitude' => 91, 'longitude' => 181,
            ]],
        ])->assertSessionHasErrors(['addresses.0.latitude', 'addresses.0.longitude']);

        $this->assertSame(0, Client::count());
    }

    public function test_a_place_id_that_is_not_one_is_refused(): void
    {
        $this->actingAs($this->user)->post(route('clients.store'), [
            'name' => 'Harborview',
            'addresses' => [[
                'label' => 'Main', 'address' => '41 Harbor Way',
                'place_id' => '<script>alert(1)</script>',
            ]],
        ])->assertSessionHasErrors('addresses.0.place_id');

        $this->assertSame(0, Client::count());
    }
}
