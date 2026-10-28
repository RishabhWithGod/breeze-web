<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a mobile account that isn't `active` from every job/task/timer/etc.
 * endpoint — `auth/me` and `auth/logout` stay reachable outside this
 * middleware so a pending/rejected technician can still see their own status
 * and sign out.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user?->status === User::STATUS_INACTIVE, 403, 'Your account has been deactivated. Please contact your manager.');
        abort_unless($user?->isActive(), 403, 'Your account is pending manager approval.');

        return $next($request);
    }
}
