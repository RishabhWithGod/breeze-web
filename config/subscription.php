<?php

/*
|--------------------------------------------------------------------------
| Subscription plans
|--------------------------------------------------------------------------
|
| What each plan costs and allows. `price` is the per-seat monthly rate —
| true per-seat billing: a company picks how many seats to buy at Payment
| Setup, Stripe is charged quantity × price, and that purchased count (held
| on the `subscriptions` row, not here) is what's enforced from then on.
|
| There is deliberately no unlimited tier any more — "Enterprise, unlimited
| users" for one flat price was exactly the thing a company could buy once
| and hand out to however many employees it wanted. Every plan, Enterprise
| included, is priced and capped by seats actually bought.
|
| `max_users` here is only the seat count the Payment Setup picker opens on
| for each plan — a sane starting point to adjust, not a ceiling.
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
            'users_label' => 'Priced per user',
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
            'users_label' => 'Priced per user',
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
            'max_users' => 10,
            'users_label' => 'Priced per user',
            // Shown beside the price on Payment Setup and the website — the
            // real rate is always advertised; this is for a large account
            // asking about a volume deal, not a way to hide the number.
            'volume_pricing_note' => 'Need more than a few seats? Contact us for volume pricing.',
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
