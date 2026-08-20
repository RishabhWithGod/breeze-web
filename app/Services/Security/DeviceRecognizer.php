<?php

namespace App\Services\Security;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * Recognises a returning device by the request's user agent alone — not the
 * IP, which changes routinely on the same physical device/network and would
 * otherwise misflag a normal reconnect as a new device.
 */
class DeviceRecognizer
{
    /** Records this device against the user; returns true if it had never been seen before. */
    public function recognize(User $user, Request $request): bool
    {
        $hash = $this->hash($request);

        $device = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_hash', $hash)
            ->first();

        $isNew = $device === null;

        if ($device) {
            $device->update(['last_seen_at' => now()]);
        } else {
            UserDevice::create([
                'user_id' => $user->id,
                'device_hash' => $hash,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return $isNew;
    }

    private function hash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent() ?: 'unknown');
    }
}
