<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in technician's own profile — the mobile equivalent of the
 * web `ProfileController`, and deliberately as small: only `name` is
 * writable here. Email and phone stay read-only on mobile too, for the
 * same reason the web `ProfileController`'s own docblock gives — changing
 * either goes through an OTP-verified challenge that lives on Security
 * Settings (web-only), not a plain field edit.
 */
class ProfileController extends Controller
{
    use ApiResponses;

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update($data);

        return $this->ok([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'initials' => $user->initials,
        ], 'Profile updated.');
    }
}
