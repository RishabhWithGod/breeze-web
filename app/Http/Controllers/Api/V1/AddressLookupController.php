<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\Places\GooglePlaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Address lookup for the mobile Site Location field — mobile's counterpart
 * to web's own `AddressLookupController`, reusing the exact same
 * `GooglePlaces` service so the key stays server-side here too: the app
 * never sees it, never restricts anything by referrer, has nothing to leak.
 */
class AddressLookupController extends Controller
{
    use ApiResponses;

    public function suggest(Request $request, GooglePlaces $places): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:160'],
            'session' => ['required', 'string', 'max:64'],
        ]);

        return $this->ok([
            'suggestions' => array_map(
                fn ($suggestion) => $suggestion->toArray(),
                $places->suggest($data['q'], $data['session']),
            ),
        ]);
    }

    public function place(Request $request, GooglePlaces $places): JsonResponse
    {
        $data = $request->validate([
            'place_id' => ['required', 'string', 'max:512'],
            'session' => ['required', 'string', 'max:64'],
        ]);

        $place = $places->details($data['place_id'], $data['session']);

        return $this->ok(['place' => $place?->toArray()]);
    }
}
