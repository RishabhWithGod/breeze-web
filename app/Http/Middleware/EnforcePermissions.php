<?php

namespace App\Http\Middleware;

use App\Services\Access\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds every web route to the signed-in person's role permissions (Roles & Permissions).
 *
 * Anything they may not do is refused with a 403, which the app shows as its own "no access" screen.
 * Routes the matrix does not cover — the dashboard, a person's own profile
 * and security, notifications — are open to everyone signed in.
 */
class EnforcePermissions
{
    public function __construct(private readonly Permissions $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();

        if ($user === null || $route === null) {
            return $next($request);
        }

        $needed = $this->permissions->required($route, $request->method());

        if ($needed === null || $this->permissions->allows($user, $needed)) {
            return $next($request);
        }

        abort(403, "Your role doesn't have access to {$this->permissions->moduleLabel($needed)}.");
    }
}
