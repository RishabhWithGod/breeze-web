<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Address lookup on the Site / Location field. A public token (`pk.…`) is
     * enough — every call goes out from the server, never the browser, so the
     * token stays out of the JS bundle. Leave it unset and the field is a
     * plain text box: an address can still be typed, it just has no
     * coordinates behind it.
     */
    'mapbox' => [
        'token' => env('MAPBOX_ACCESS_TOKEN'),
        /* Bias results towards where the work is. Comma-separated ISO codes. */
        'countries' => env('MAPBOX_COUNTRIES', 'us,ca'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
