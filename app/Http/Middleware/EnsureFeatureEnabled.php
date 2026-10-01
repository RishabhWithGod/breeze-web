<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a feature that is switched off out of reach: its addresses answer "not
 * found", as though they had never been built. Used as `feature:estimate_builder`.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(config("features.{$feature}") === true, 404);

        return $next($request);
    }
}
