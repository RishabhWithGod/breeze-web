<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityAlertRaised;
use Illuminate\Http\Request;

/**
 * The single writer for `security_events` — the audit log. Every row uses
 * the real request IP/user agent; nothing here is ever hard-coded or
 * back-filled with a plausible-looking placeholder.
 */
class SecurityEventLogger
{
    public function log(?User $user, string $type, string $description, Request $request): SecurityEvent
    {
        $event = SecurityEvent::create([
            'user_id' => $user?->id,
            'type' => $type,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'occurred_at' => now(),
        ]);

        if ($user) {
            $user->notify(new SecurityAlertRaised($event));
        }

        return $event;
    }
}
