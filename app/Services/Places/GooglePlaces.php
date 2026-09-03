<?php

namespace App\Services\Places;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Address lookup, through Google Places (New).
 *
 * Two calls, and only two. Typing asks Autocomplete for names; picking one
 * asks Place Details for the point behind it. Details is never asked per
 * keystroke — that is the whole reason `place_id` exists.
 *
 * A session token ties the two together: every keystroke of one search and the
 * details call that ends it are billed as a single session rather than
 * separately. The caller supplies it and throws it away after a selection,
 * which is what makes a session a session.
 *
 * The key never reaches the browser. The field talks to our own endpoint and
 * this talks to Google, so there is nothing to restrict by referrer and
 * nothing to leak from a page source.
 *
 * Every failure path is quiet and empty: an unconfigured key, a refused
 * request, a network that is down, billing that is off. The address field is
 * an ordinary text box when this returns nothing, so a person can always
 * finish what they were doing.
 */
class GooglePlaces
{
    private const AUTOCOMPLETE = 'https://places.googleapis.com/v1/places:autocomplete';

    private const DETAILS = 'https://places.googleapis.com/v1/places/';

    /** Below this a search matches half the country and bills for the privilege. */
    private const MINIMUM_QUERY = 3;

    /** True when a key is configured — the field says so rather than looking broken. */
    public function configured(): bool
    {
        return filled(config('services.google_places.key'));
    }

    /**
     * Addresses matching what has been typed so far.
     *
     * @return list<PlaceSuggestion>
     */
    public function suggest(string $query, string $sessionToken): array
    {
        $query = trim($query);

        if (! $this->configured() || mb_strlen($query) < self::MINIMUM_QUERY) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Goog-Api-Key' => config('services.google_places.key'),
                    // Only the fields the list draws. Autocomplete bills by
                    // what you ask for.
                    'X-Goog-FieldMask' => implode(',', [
                        'suggestions.placePrediction.placeId',
                        'suggestions.placePrediction.text',
                        'suggestions.placePrediction.structuredFormat',
                    ]),
                ])
                ->post(self::AUTOCOMPLETE, array_filter([
                    'input' => $query,
                    'sessionToken' => $sessionToken,
                    // Addresses, not businesses or bus stops: this field asks
                    // where the work is.
                    'includedPrimaryTypes' => ['street_address', 'premise', 'subpremise', 'route'],
                    'includedRegionCodes' => $this->regions(),
                ]));

            if ($response->failed()) {
                $this->note('Google Places refused an autocomplete request.', $response->status());

                return [];
            }

            return $this->readSuggestions($response->json('suggestions') ?? []);
        } catch (ConnectionException $e) {
            $this->note('Address lookup could not reach Google Places.', null, $e);

            return [];
        } catch (Throwable $e) {
            $this->note('Address lookup failed unexpectedly.', null, $e);

            return [];
        }
    }

    /**
     * The place behind a suggestion: its address, its point, its components.
     *
     * Called once, when a person picks — never while they type.
     */
    public function details(string $placeId, string $sessionToken): ?ResolvedPlace
    {
        if (! $this->configured() || $placeId === '') {
            return null;
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Goog-Api-Key' => config('services.google_places.key'),
                    'X-Goog-FieldMask' => 'id,formattedAddress,location,addressComponents',
                ])
                ->get(self::DETAILS.rawurlencode($placeId), [
                    'sessionToken' => $sessionToken,
                ]);

            if ($response->failed()) {
                $this->note('Google Places refused a details request.', $response->status());

                return null;
            }

            return $this->readPlace($response->json() ?? []);
        } catch (ConnectionException $e) {
            $this->note('Place details could not reach Google Places.', null, $e);

            return null;
        } catch (Throwable $e) {
            $this->note('Place details failed unexpectedly.', null, $e);

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return list<PlaceSuggestion>
     */
    private function readSuggestions(array $suggestions): array
    {
        $read = [];

        foreach ($suggestions as $suggestion) {
            $prediction = $suggestion['placePrediction'] ?? null;
            $placeId = $prediction['placeId'] ?? null;
            $label = $prediction['text']['text'] ?? null;

            // A prediction without an id cannot be resolved later, so it is
            // not something anyone can usefully pick.
            if (! is_string($placeId) || $placeId === '' || ! is_string($label)) {
                continue;
            }

            $read[] = new PlaceSuggestion(
                placeId: $placeId,
                label: $label,
                primary: $prediction['structuredFormat']['mainText']['text'] ?? $label,
                secondary: $prediction['structuredFormat']['secondaryText']['text'] ?? '',
            );
        }

        return $read;
    }

    /** @param  array<string, mixed>  $payload */
    private function readPlace(array $payload): ?ResolvedPlace
    {
        $latitude = $payload['location']['latitude'] ?? null;
        $longitude = $payload['location']['longitude'] ?? null;
        $address = $payload['formattedAddress'] ?? null;
        $placeId = $payload['id'] ?? null;

        // Half a place is worse than none: an address with no point would be
        // stored as located when it is not.
        if (! is_numeric($latitude) || ! is_numeric($longitude) || ! is_string($address) || ! is_string($placeId)) {
            return null;
        }

        return new ResolvedPlace(
            placeId: $placeId,
            address: $address,
            latitude: (float) $latitude,
            longitude: (float) $longitude,
            components: $this->readComponents($payload['addressComponents'] ?? []),
        );
    }

    /**
     * The few components worth keeping. Google returns a dozen; a job sheet
     * needs the town, the state, the postcode and the country.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, string>
     */
    private function readComponents(array $components): array
    {
        $wanted = [
            'locality' => 'city',
            'administrative_area_level_1' => 'region',
            'postal_code' => 'postcode',
            'country' => 'country',
        ];

        $read = [];

        foreach ($components as $component) {
            foreach ($component['types'] ?? [] as $type) {
                if (isset($wanted[$type]) && filled($component['longText'] ?? null)) {
                    $read[$wanted[$type]] = $component['longText'];
                }
            }
        }

        return $read;
    }

    /** @return list<string>|null */
    private function regions(): ?array
    {
        $regions = array_filter(array_map(
            'trim',
            explode(',', (string) config('services.google_places.regions')),
        ));

        return $regions === [] ? null : array_values($regions);
    }

    /**
     * Logged without the key, the query or the response — a lookup failing is
     * an operational fact, not a place to put someone's address or a secret.
     */
    private function note(string $message, ?int $status = null, ?Throwable $e = null): void
    {
        Log::warning($message, array_filter([
            'status' => $status,
            'exception' => $e?->getMessage(),
        ]));
    }
}
