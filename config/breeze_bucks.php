<?php

use App\Models\RewardRule;

return [
    /**
     * Breeze Bucks is paused. While this is off nothing is earned (no points
     * from tasks, takeoffs or approved time), nothing is redeemed or awarded,
     * no Breeze Bucks notification is sent, and its screens answer 404. Turn it
     * back on with BREEZE_BUCKS_ENABLED=true — nothing else needs to change.
     */
    'enabled' => (bool) env('BREEZE_BUCKS_ENABLED', false),

    /**
     * Seed values for `reward_rules` — only used the first time each event
     * type's row is created (mirrors `payments.php`'s defaults). Editing the
     * database row afterwards is what actually governs point values.
     */
    'rules' => [
        RewardRule::TIME_ENTRY_APPROVED => [
            'points' => 50,
            'description' => 'Earned 50 BB for an approved time entry.',
        ],
        RewardRule::AI_TAKEOFF_COMPLETED => [
            'points' => 100,
            'description' => 'Earned 100 BB for a completed AI Takeoff.',
        ],
        RewardRule::JOB_TASK_COMPLETED => [
            'points' => 25,
            'description' => 'Earned 25 BB for a completed task.',
        ],
    ],

    'history_per_page' => 10,
];
