<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Static (DB-backed) AI Takeoff
    |--------------------------------------------------------------------------
    |
    | An isolated, removable alternative to the dynamic AI engine
    | (`App\Services\Ai\AiTakeoffClient`). When enabled, `StaticTakeoffEngine`
    | answers a takeoff run from a pre-stored `static_takeoff_datasets` row
    | matched by the uploaded file's content hash, instead of calling the
    | external engine. Everything downstream of the analyse call — review,
    | finalise, estimate, job — is the same, unmodified pipeline either way.
    |
    | Disabled by default: the dynamic engine behaves exactly as before.
    |
    */

    'enabled' => (bool) env('STATIC_TAKEOFF_ENABLED', false),

    /*
    | With this off (the default), a PDF with no matching static dataset fails
    | the run cleanly rather than silently calling the real AI engine. Turn it
    | on only if an unmatched drawing should fall back to the dynamic engine.
    */
    'fallback_to_dynamic' => (bool) env('STATIC_TAKEOFF_FALLBACK_TO_DYNAMIC', false),

    /*
    | A matched dataset resolves in milliseconds — held here, on the queue
    | worker, only long enough that the processing screen still reads as a
    | run in progress instead of skipping straight to "done". Kept short:
    | this is purely so the reviewer sees the screen, not a real wait. Set
    | to 0 to answer as fast as possible (skips the screen almost entirely).
    */
    'simulated_processing_seconds' => (int) env('STATIC_TAKEOFF_SIMULATED_SECONDS', 3),

];
