<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a typed address into the handful of real places it could be, each with
 * its coordinates — Mapbox's forward geocoder, one HTTP call, no SDK.
 *
 * Called from the server rather than the browser so the token never reaches the
 * JS bundle, and so swapping the provider later touches one class.
 *
 * Every failure path returns no suggestions rather than throwing: the address
 * field must stay usable as a plain text box when the token is missing, the
 * network is down, or Mapbox answers with an error. A typed address with no
 * coordinates behind it is a worse record, not a broken one.
 */
class MapboxGeocoder
{
    private const ENDPOINT = 'https://api.mapbox.com/geocoding/v5/mapbox.places/';

    /** Enough to choose from without turning the dropdown into a list to read. */
    private const LIMIT = 5;

    public function configured(): bool
    {
        return filled(config('services.mapbox.token'));
    }

    /**
     * @return list<AddressSuggestion>
     */
    public function search(string $query): array
    {
        $query = trim($query);

        // Two characters match half a country; there is nothing to suggest yet.
        if (! $this->configured() || mb_strlen($query) < 3) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get(self::ENDPOINT.rawurlencode($query).'.json', [
                    'access_token' => config('services.mapbox.token'),
                    'limit' => self::LIMIT,
                    'country' => config('services.mapbox.countries'),
                    // A job site is a street address or a place, never a
                    // country or a postcode on its own.
                    'types' => 'address,place,poi,neighborhood,locality',
                    'autocomplete' => 'true',
                ]);
        } catch (Throwable $e) {
            Log::warning('Address lookup could not reach Mapbox.', ['message' => $e->getMessage()]);

            return [];
        }

        if ($response->failed()) {
            Log::warning('Address lookup failed.', [
                'status' => $response->status(),
                'message' => $response->json('message'),
            ]);

            return [];
        }

        return $this->parse($response->json('features') ?? []);
    }

    /**
     * @param  array<int, mixed>  $features
     * @return list<AddressSuggestion>
     */
    private function parse(array $features): array
    {
        $suggestions = [];

        foreach ($features as $feature) {
            $label = data_get($feature, 'place_name');
            // Mapbox orders `center` as [longitude, latitude], not the other
            // way round — reading it the wrong way puts every US site in China.
            $longitude = data_get($feature, 'center.0');
            $latitude = data_get($feature, 'center.1');

            if (! is_string($label) || ! is_numeric($latitude) || ! is_numeric($longitude)) {
                continue;
            }

            $suggestions[] = new AddressSuggestion($label, (float) $latitude, (float) $longitude);
        }

        return $suggestions;
    }
}
