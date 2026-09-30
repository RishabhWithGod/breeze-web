<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default exclusions
    |--------------------------------------------------------------------------
    |
    | What a new estimate says it does not cover, until the estimator changes it.
    | Each estimate keeps its own list; editing one never touches these.
    */
    'default_exclusions' => [
        'Low voltage systems (data, AV, security)',
        'Furniture, fixtures, and equipment (FF&E)',
        'Utility company fees and service connection charges',
        'Permits and impact fees (by owner)',
        'Hazardous material remediation',
        'Work outside of normal business hours',
        'Anything not specifically listed in the Scope of Work above',
    ],

    /** Roles that may approve an estimate or send it back for edits. */
    'reviewer_roles' => ['project manager', 'estimator', 'supervisor', 'admin', 'owner'],
];
