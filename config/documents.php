<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    |
    | The size ceiling is shared with `config('takeoff.uploads')` — one number
    | for "how big a file this app accepts", enforced the same way through
    | `App\Support\UploadLimits`, so electrical drawing PDFs uploaded here are
    | never held to a stricter limit than the AI Takeoff screen already allows.
    |
    | The extension list is its own, wider, since Documents also carries
    | specs, contracts and photos that Takeoff never needs to parse.
    |
    */

    'disk' => 'local',

    'directory' => 'documents',

    'extensions' => [
        'pdf', 'dwg', 'dxf',
        'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt',
        'png', 'jpg', 'jpeg', 'gif',
    ],

    'per_page' => 10,

];
