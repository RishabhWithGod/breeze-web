<?php

namespace App\Services\Activity;

use App\Models\FeedItem;
use App\Models\User;

/**
 * The one writer of real `feed_items` rows going forward.
 *
 * Existing seeded rows (position >= 0) are left exactly as they were
 * created — legitimate reference/demo content, not something this class
 * touches. A new real event is given a position *below* zero, one less
 * than whatever the lowest position in that scope currently is, so it
 * always sorts ahead of everything that came before it — including other
 * real rows recorded earlier — without ever renumbering history.
 *
 * Stamped with the acting manager, so their dashboard never narrates another
 * manager's jobs and invoices — see `FeedItem::scopeVisibleTo()`.
 */
class FeedItemRecorder
{
    public function record(User $user, string $scope, string $text, string $icon, string $tile = 'lilac'): FeedItem
    {
        $lowest = (int) FeedItem::where('scope', $scope)->min('position');
        $position = min(0, $lowest) - 1;

        return FeedItem::create([
            'user_id' => $user->id,
            'scope' => $scope,
            'segments' => [['text' => $text]],
            'icon' => $icon,
            'tile' => $tile,
            'position' => $position,
        ]);
    }
}
