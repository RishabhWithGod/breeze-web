<?php

namespace App\Http\Controllers;

use App\Services\Geocoding\MapboxGeocoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address suggestions for the Site / Location field.
 *
 * The browser asks this rather than Mapbox directly, so the token stays
 * server-side and the app has one throttle in front of a metered service.
 */
class AddressLookupController extends Controller
{
    public function __invoke(Request $request, MapboxGeocoder $geocoder): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:160'],
        ]);

        return response()->json([
            'suggestions' => array_map(
                fn ($suggestion) => $suggestion->toArray(),
                $geocoder->search($validated['q']),
            ),
        ]);
    }
}
