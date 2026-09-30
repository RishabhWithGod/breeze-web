<?php

/*
|--------------------------------------------------------------------------
| Subscription plans
|--------------------------------------------------------------------------
|
| What each plan costs and allows. Every plan has one fixed monthly `price`
| and a `max_users` — how many people can hold an active account on it (`null`
| is no limit). Enterprise's price is advertised like the others.
|
| PRICES BELOW ARE PLACEHOLDERS TO BE CONFIRMED before launch.
|
| `storage_gb` is what the drawings on file may add up to.
*/

return [
    'default_plan' => 'growth',

    /** Off the price when a company pays for a year at once. */
    'annual_discount_percent' => 20,

    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'tagline' => 'For small teams getting started',
            'description' => 'For a small crew getting its first jobs takeoff and estimated.',
            'price' => 49,
            'max_users' => 2,
            'users_label' => 'Up to 2 users',
            'popular' => false,
            'limits' => ['ai_takeoffs' => 50, 'estimates' => 50, 'projects' => 25, 'storage_gb' => 10],
            'highlights' => ['Core takeoff features', 'Email support'],
            'features' => ['AI-Powered Takeoffs', 'Estimates', 'Project Management', 'Email Support'],
        ],
        'growth' => [
            'name' => 'Growth',
            'tagline' => 'For growing construction teams',
            'description' => 'Everything you need to takeoff, estimate, and manage projects with AI.',
            'price' => 149,
            'max_users' => 10,
            'users_label' => 'Up to 10 users',
            'popular' => true,
            'limits' => ['ai_takeoffs' => 500, 'estimates' => 200, 'projects' => 100, 'storage_gb' => 50],
            'highlights' => ['Advanced takeoff tools', 'Integrations', 'Priority support'],
            'features' => [
                'Advanced takeoff tools',
                'Project collaboration',
                'Integrations',
                'Priority support',
                'Regular feature updates',
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'tagline' => 'For large organizations',
            'description' => 'For larger companies running many crews and jobs at once.',
            'price' => 249,
            'max_users' => null,
            'users_label' => 'Unlimited users',
            'popular' => false,
            'limits' => ['ai_takeoffs' => 2000, 'estimates' => 1000, 'projects' => 500, 'storage_gb' => 250],
            'highlights' => ['Custom integrations', 'Dedicated support', 'Advanced security'],
            'features' => [
                'Advanced takeoff tools',
                'Project collaboration',
                'Custom integrations',
                'Dedicated support',
                'Advanced security',
                'Regular feature updates',
            ],
        ],
    ],
];
