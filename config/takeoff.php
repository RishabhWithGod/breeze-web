<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | Enforced by StoreUploadRequest and mirrored to the dropzone as page props,
    | so the client and server can never disagree about what is accepted.
    |
    */

    'uploads' => [
        // One drawing at a time — kept as a single setting so the AI Takeoff
        // upload screen and the project PDF picker can never disagree about it.
        'max_files' => 1,
        'max_file_size_mb' => (int) env('TAKEOFF_MAX_FILE_SIZE_MB', 500),
        'extensions' => ['pdf', 'dwg', 'dxf', 'bim', 'ifc', 'rvt'],
        'disk' => 'local',
        'directory' => 'uploads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline stages
    |--------------------------------------------------------------------------
    |
    | The takeoff pipeline this prototype stands in for. Durations drive the
    | progress animation on the processing screen; the server owns the list so
    | the stages shown always match the stages it reports.
    |
    */

    'stages' => [
        [
            'id' => 'upload',
            'label' => 'Uploading PDF',
            'description' => 'Transferring your drawing set to the secure workspace.',
            'durationMs' => 2200,
        ],
        [
            'id' => 'prepare',
            'label' => 'Preparing Document',
            'description' => 'Normalising page sizes, rotation and vector layers.',
            'durationMs' => 2000,
        ],
        [
            'id' => 'read',
            'label' => 'Reading Pages',
            'description' => 'Rasterising sheets and extracting embedded text.',
            'durationMs' => 2600,
        ],
        [
            'id' => 'analyze',
            'label' => 'Analyzing Drawing',
            'description' => 'Mapping circuits, panels and homerun paths.',
            'durationMs' => 3000,
        ],
        [
            'id' => 'symbols',
            'label' => 'Detecting Symbols',
            'description' => 'Matching legend entries against detected geometry.',
            'durationMs' => 3200,
        ],
        [
            'id' => 'takeoff',
            'label' => 'Generating Takeoff',
            'description' => 'Counting devices and calculating material quantities.',
            'durationMs' => 2800,
        ],
        [
            'id' => 'results',
            'label' => 'Preparing Results',
            'description' => 'Building the review dashboard and export bundle.',
            'durationMs' => 1800,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    'per_page' => 5,

    /*
    |--------------------------------------------------------------------------
    | Processing screen polling
    |--------------------------------------------------------------------------
    |
    | The engine reports no intermediate progress, so the screen has to ask. How
    | it asks is the whole cost: a run lasting a minute used to mean about
    | twenty-five full requests, each booting the framework, reading and writing
    | the session and loading four models, only to report the same thing.
    |
    | With `hold_seconds` above zero the status request waits instead of
    | answering immediately, and returns the moment the run's state actually
    | changes. One request covers the whole wait, and the browser learns of a
    | change in `tick_ms` rather than on the next interval.
    |
    | The wait occupies a PHP worker for its duration. That is fine under FPM or
    | Octane, and fine under `php artisan serve` with PHP_CLI_SERVER_WORKERS set
    | — but a single-process built-in server has exactly one worker, and holding
    | it would freeze the whole site. `ProcessingController` detects that case
    | and answers immediately regardless of this setting.
    |
    | Set `hold_seconds` to 0 to go back to plain interval polling.
    |
    */

    'polling' => [
        /* Fallback interval, and the cadence used when long polling is off. */
        'interval_ms' => (int) env('TAKEOFF_POLL_INTERVAL_MS', 2500),
        /* How long one status request may wait for a change. */
        'hold_seconds' => (int) env('TAKEOFF_POLL_HOLD_SECONDS', 20),
        /* How often the held request re-checks. One cheap primary-key select. */
        'tick_ms' => (int) env('TAKEOFF_POLL_TICK_MS', 1000),
    ],

];
