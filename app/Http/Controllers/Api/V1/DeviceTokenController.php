<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Where the mobile app tells the server which phone to push this user's notifications to. */
class DeviceTokenController extends Controller
{
    use ApiResponses;

    /** Idempotent: registering the same token again just refreshes it. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'in:ios,android'],
        ]);

        // Keyed by token alone, so a phone that switches account moves to the new user.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_used_at' => now()],
        );

        return $this->ok(null, 'Device registered.');
    }

    /** Sign-out: stop pushing to this phone. Only ever removes the caller's own row. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);

        DeviceToken::where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return $this->ok(null, 'Device removed.');
    }
}
