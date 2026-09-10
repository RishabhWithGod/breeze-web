<?php

namespace App\Http\Middleware;

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
        abort_unless($request->user()?->isActive(), 403, 'Your account is pending manager approval.');

        return $next($request);
    }
}
