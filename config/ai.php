<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI takeoff backend
    |--------------------------------------------------------------------------
    |
    | The "Electrical Drawing AI Takeoff Engine" (FastAPI). Laravel is the
    | orchestration layer around it: it stores the drawing, posts it to the
    | engine, keeps the response verbatim as the audit record, and walks a
    | reviewer through approving it.
    |
    | `POST /api/upload` is synchronous — it returns the whole AnalysisResult in
    | one response — so there is no polling contract here. Analysis of a large
    | drawing set takes tens of seconds, which is why the request runs on a queue
    | with a long timeout.
    |
    | With `base_url` unset the upload flow fails loudly rather than inventing
    | results.
    |
    */

    'base_url' => env('AI_API_BASE_URL'),
    'key' => env('AI_API_KEY'),

    /*
    | How the key is presented, for deployments that put the engine behind a
    | gateway. The engine itself is unauthenticated.
    */
    'auth' => [
        'mode' => env('AI_API_AUTH_MODE', 'bearer'),
        'header' => env('AI_API_KEY_HEADER', 'X-API-Key'),
    ],

    /*
    | Paths on the engine, relative to `base_url`, exactly as published by its
    | OpenAPI document. `:run` and `:image` are substituted per request.
    */
    'endpoints' => [
        'health' => env('AI_API_HEALTH_PATH', 'api/health'),
        'upload' => env('AI_API_UPLOAD_PATH', 'api/upload'),

        // Learning loop: reviewer decisions are pushed back so the engine's
        // symbol library improves.
        'approve' => env('AI_API_APPROVE_PATH', 'api/review/approve'),
        'reject' => env('AI_API_REJECT_PATH', 'api/review/reject'),
        'rename' => env('AI_API_RENAME_PATH', 'api/review/rename'),
        'merge' => env('AI_API_MERGE_PATH', 'api/review/merge'),

        // Per-crop detail: bounding boxes, pipeline stages and crop images.
        'lifecycle' => env('AI_API_LIFECYCLE_PATH', 'api/debug/lifecycle/:run'),
        'lifecycle_image' => env('AI_API_LIFECYCLE_IMAGE_PATH', 'api/debug/lifecycle/:run/image/:image'),
        'review_image' => env('AI_API_REVIEW_IMAGE_PATH', 'api/review/image/:run/:image'),

        /*
         * The engine's own per-page raster size (in the exact pixel space its
         * detections are reported in) and the DPI it rendered at. This is the
         * only reliable source for mapping a bbox onto a drawing page — the
         * upload response itself carries no page dimensions.
         */
        'page_info' => env('AI_API_PAGE_INFO_PATH', 'api/review/page-info/:run'),
    ],

    /*
    | The learning loop. Reviewer decisions are pushed to the engine so its symbol
    | library improves; turn it off to review without teaching.
    */
    'learning' => [
        'enabled' => (bool) env('AI_LEARNING_ENABLED', true),
    ],

    /* Name of the multipart field the drawing is posted under. */
    'file_field' => env('AI_API_FILE_FIELD', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | `connect` bounds how long we wait for the engine to answer *at all*; without
    | it an unroutable host holds the request open for the whole read window. It is
    | deliberately short: the engine is either there or it is not.
    |
    | `analyse` covers the whole synchronous analysis, so it is measured in minutes
    | — but it only ever runs on a queue worker. `read` covers the cheap calls
    | (health, lifecycle, crop images) and is kept small enough that no page render
    | can ever be held up by the engine.
    |
    */
    'timeout' => [
        'connect' => (float) env('AI_API_CONNECT_TIMEOUT', 2),
        'analyse' => (int) env('AI_API_ANALYSE_TIMEOUT', 900),
        'read' => (int) env('AI_API_READ_TIMEOUT', 10),
        /* Health is on the critical path of a screen, so it gets the tightest budget. */
        'health' => (float) env('AI_API_HEALTH_TIMEOUT', 2),
    ],

    'retries' => [
        /*
         * Only idempotent reads are retried, with exponential backoff. The analysis
         * itself is never retried inside one request — it is expensive and not
         * cheaply idempotent, so the queued job owns that decision.
         */
        'times' => (int) env('AI_API_RETRY_TIMES', 3),
        'base_sleep_ms' => (int) env('AI_API_RETRY_SLEEP_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic handoff
    |--------------------------------------------------------------------------
    |
    | With this on, a drawing that comes back from the engine gets its job and
    | estimate immediately, from the AI response, so both modules reflect the drawing
    | without waiting on a reviewer. Signing off the review rewrites those same two
    | records at the reviewed counts — it never creates duplicates.
    |
    | Turn it off to require a signed-off review before either exists.
    |
    */
    'auto_handoff' => (bool) env('AI_AUTO_HANDOFF', false),

    /*
    |--------------------------------------------------------------------------
    | Engine capacity
    |--------------------------------------------------------------------------
    |
    | The engine runs as a single uvicorn worker, so it analyses one drawing at a
    | time. Sending it two does not halve the wall clock — the second waits inside
    | the engine, and while it waits the cheap endpoints (health, lifecycle) queue
    | behind it too, which is far worse than simply taking turns.
    |
    | With this on, `ProcessTakeoffRun` takes a lock so only one analysis is in
    | flight regardless of how many queue workers are running. Every other job —
    | previews, crop backfill — still runs alongside.
    |
    | Turn it off only if the engine is started with several workers, or sits
    | behind a load balancer with more than one instance.
    |
    */
    'one_run_at_a_time' => (bool) env('AI_ONE_RUN_AT_A_TIME', true),

    /*
    | How long the engine's health is trusted before asking again. Health is polled
    | by the upload screen, so caching it keeps that poll free.
    */
    'health_cache_seconds' => (int) env('AI_API_HEALTH_CACHE', 15),

    /*
    |--------------------------------------------------------------------------
    | Per-crop lifecycle
    |--------------------------------------------------------------------------
    |
    | `GET /api/debug/lifecycle/{run_id}` is what gives the review cards their
    | crop image, bounding box, page and pipeline stage trail. The upload
    | response does not currently carry its `run_id`, so it has to be resolved.
    |
    | `directory` is the engine's debug directory, used *only* to resolve which
    | run belongs to the drawing just analysed (newest run whose lifecycle
    | project_name matches). All crop data itself is then read over HTTP.
    |
    | Leave it unset when the engine runs on another host: ingest still works
    | from the upload response alone, and cards simply carry no crop image.
    | Adding `run_id` to the upload response would remove the need for this.
    |
    */

    'lifecycle' => [
        'enabled' => (bool) env('AI_LIFECYCLE_ENABLED', true),
        'directory' => env('AI_LIFECYCLE_DIR'),
        /* Only consider runs created within this many seconds of the upload. */
        'max_age_seconds' => (int) env('AI_LIFECYCLE_MAX_AGE', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Artefact storage
    |--------------------------------------------------------------------------
    |
    | Every run keeps: the original PDF, the AI response verbatim, the reviewed
    | final response, page previews, a thumbnail, crop images and the annotated
    | PDF.
    |
    */

    'storage' => [
        'disk' => env('AI_STORAGE_DISK', 'local'),
        'directory' => 'takeoffs',
        /* `pdftoppm` renders page previews; runs are unaffected when absent. */
        'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
        'preview_dpi' => (int) env('AI_PREVIEW_DPI', 110),
        'max_preview_pages' => (int) env('AI_MAX_PREVIEW_PAGES', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Estimating
    |--------------------------------------------------------------------------
    |
    | The engine returns its own bill of quantities and estimate, which is what
    | the generated estimate is built from. These only fill gaps: `markup_pct` is
    | not part of the engine's model, and `tax_pct` is the fallback when a
    | response carries no tax rate.
    |
    */

    'estimating' => [
        'markup_pct' => (float) env('ESTIMATE_MARKUP_PCT', 0),
        'tax_pct' => (float) env('ESTIMATE_TAX_PCT', 8.25),
        'labor_rate' => (float) env('ESTIMATE_LABOR_RATE', 78),
    ],

];
