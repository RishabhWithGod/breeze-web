<?php

namespace App\Http\Middleware;

use App\Models\Foreman;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks an apprentice from every task/material/status/review/approval
 * endpoint on the mobile API — applied to those route groups only, never to
 * `jobs.index`/`jobs.show` (their own job, trimmed to basic info by
 * `Api\V1\JobController` itself) or `attendance.*` (check in/out, their one
 * allowed action).
 *
 * A route guard rather than a check duplicated into a dozen controllers:
 * every endpoint this wraps stays untouched, and there is exactly one place
 * that knows apprentices don't reach them.
 */
class BlockApprenticeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $request->user()?->foreman?->role === Foreman::ROLE_APPRENTICE,
            403,
            'This action is not available to an apprentice.',
        );

        return $next($request);
    }
}
