<?php

use App\Models\RewardRule;

return [
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
