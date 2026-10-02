<?php

/*
|--------------------------------------------------------------------------
| Subscription plans
|--------------------------------------------------------------------------
|
| What each plan costs and allows. Every plan has one fixed monthly `price`
| and a `max_users` — how many people can hold an active account on it (`null`
| is no limit). Enterprise-style unlimited tiers are advertised like the others.
|
| Prices below are the official per-user monthly rate. Billed here as one
| flat company-wide price with a seat cap, not true per-seat billing —
| building real per-seat billing (a seat count collected at Payment Setup,
| Stripe quantity wired to it) is a follow-up, not done here. `max_users`
| below is each plan's seat cap at that per-user rate.
|
| `storage_gb` is what the drawings on file may add up to.
*/

return [
    'default_plan' => 'growth',

    /*
    | The sheet's Annual column is the monthly price × 12 (no extra discount
    | baked in) — annual just lets a company pay the year in one go.
    */
    'annual_discount_percent' => 0,

    'plans' => [
        'basic' => [
            'name' => 'Breeze Basic',
            'tagline' => 'For employees — time tracking & job photos',
            'description' => 'CompanyCam- and Exaktime-style features for every employee: GPS time tracking, job photos, and daily logs.',
            'price' => 150.00,
            'max_users' => 5,
            'users_label' => 'Up to 5 users',
            'popular' => false,
            'limits' => ['ai_takeoffs' => 10, 'estimates' => 25, 'projects' => 15, 'storage_gb' => 10],
            'highlights' => ['GPS time tracking', 'Job photos & daily logs', 'Email support'],
            'features' => ['GPS Time Tracking', 'Job Site Photos', 'Daily Logs', 'Email Support'],
        ],
        'growth' => [
            'name' => 'Breeze Growth',
            'tagline' => 'For foremen & project managers',
            'description' => 'Everything in Breeze Basic, plus Jobber-style job and crew management for the people running the work.',
            'price' => 300.00,
            'max_users' => 25,
            'users_label' => 'Up to 25 users',
            'popular' => true,
            'limits' => ['ai_takeoffs' => 100, 'estimates' => 100, 'projects' => 60, 'storage_gb' => 50],
            'highlights' => ['Everything in Breeze Basic', 'Job & crew management', 'Priority support'],
            'features' => [
                'Everything in Breeze Basic',
                'Job & Crew Management',
                'Scheduling',
                'Priority Support',
                'Regular Feature Updates',
            ],
        ],
        'platinum' => [
            'name' => 'Breeze Platinum',
            'tagline' => 'Breeze Growth, plus AI Estimating',
            'description' => 'Everything in Breeze Growth, plus the full AI-powered takeoff and estimating engine.',
            'price' => 500.00,
            'max_users' => null,
            'users_label' => 'Unlimited users',
            'popular' => false,
            'limits' => ['ai_takeoffs' => 1000, 'estimates' => 500, 'projects' => 300, 'storage_gb' => 250],
            'highlights' => ['Everything in Breeze Growth', 'AI-powered takeoffs & estimating', 'Dedicated support'],
            'features' => [
                'Everything in Breeze Growth',
                'AI-Powered Takeoffs',
                'AI-Powered Estimating',
                'Dedicated Support',
                'Advanced Security',
            ],
        ],
    ],
];
