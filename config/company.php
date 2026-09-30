<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setup for accounts that already existed
    |--------------------------------------------------------------------------
    |
    | A manager account with no company — one from before companies existed —
    | is sent through company setup, terms and payment the next time it opens
    | the app. Set this to false to leave such accounts alone (the test suite
    | does, since most of its users are exactly that).
    */
    'require_setup_for_existing' => env('REQUIRE_COMPANY_SETUP_FOR_EXISTING', true),
];
