<?php

namespace App\Services\BreezeBucks;

use App\Models\RewardCatalogItem;

/**
 * The "X BB until your next reward" figure — the closest active reward
 * whose cost is still above the given balance. Never a hard-coded number.
 */
class RewardProgressCalculator
{
    /** @return array{reward: RewardCatalogItem, remaining: int, percent: float}|null */
    public function nextRewardFor(int $balance): ?array
    {
        $next = RewardCatalogItem::query()
            ->where('is_active', true)
            ->where('points_required', '>', $balance)
            ->orderBy('points_required')
            ->first();

        if (! $next) {
            return null;
        }

        return [
            'reward' => $next,
            'remaining' => $next->points_required - $balance,
            'percent' => $next->points_required > 0 ? min(100, ($balance / $next->points_required) * 100) : 100,
        ];
    }
}
