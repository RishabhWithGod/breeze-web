<?php

/*
|--------------------------------------------------------------------------
| Get Started
|--------------------------------------------------------------------------
|
| Where the "Need Help?" links on the setup screen go. A link with no address is
| not shown, and with none set the card does not appear at all — there are no
| placeholder links to nowhere.
*/

return [
    'help_links' => [
        'documentation' => env('HELP_DOCUMENTATION_URL'),
        'setup_guide' => env('HELP_SETUP_GUIDE_URL'),
        'support' => env('HELP_SUPPORT_URL'),
    ],
];
