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
     * Address lookup on the Site / Location field — Google Places (New).
     *
     * Every call goes out from the server, never the browser, so the key stays
     * out of the JS bundle entirely. Leave it unset and the field is a plain
     * text box: an address can still be typed, it just has no coordinates
     * behind it.
     */
    'google_places' => [
        /*
         * Server-side only. Restrict it by API to "Places API (New)"; it needs
         * no referrer restriction, because no browser ever sends it.
         */
        'key' => env('GOOGLE_PLACES_API_KEY'),
        /*
         * Which countries to search. Comma-separated ISO codes, or empty to
         * search everywhere.
         */
        'regions' => env('GOOGLE_PLACES_REGIONS', 'us,ca'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
