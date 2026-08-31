<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Two health checks, for two different questions a load balancer / process
 * supervisor asks:
 *
 * - `/health/live` — is this PHP process able to answer a request at all?
 *   No dependency is checked; a slow database must never turn into "this
 *   instance is dead and should be killed."
 * - `/health/ready` — can this instance actually serve real traffic right
 *   now? Checks the dependencies a request would actually need (DB, the
 *   queue connection's own table) without exposing any secret in the
 *   response — a boolean per dependency, nothing else.
 *
 * Laravel's own built-in `/up` (registered via `health: '/up'` in
 * bootstrap/app.php) stays as-is for anything already wired to it; these
 * are additive, not a replacement.
 */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'queue' => $this->checkQueue(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Queued broadcasts/notifications/AI jobs all depend on this being
     * reachable. This app's queue connection is `database` (see
     * docs/realtime.md) — if that ever changes, this check should move to
     * whatever ping the new driver supports rather than assuming the
     * `jobs` table forever.
     */
    private function checkQueue(): bool
    {
        if (config('queue.default') !== 'database') {
            return true;
        }

        try {
            DB::table('jobs')->limit(1)->exists();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
