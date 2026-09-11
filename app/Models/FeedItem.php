<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeedItem extends Model
{
    public const DASHBOARD_ACTIVITY = 'dashboard_activity';

    public const DASHBOARD_NOTIFICATIONS = 'dashboard_notifications';

    public const HISTORY_ACTIVITY = 'history_activity';

    protected $fillable = ['user_id', 'scope', 'segments', 'detail', 'meta', 'icon', 'tile', 'position'];

    protected function casts(): array
    {
        return ['segments' => 'array'];
    }

    public function scopeScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope)->orderBy('position');
    }

    /**
     * This manager's own real activity, plus the seeded reference rows every
     * dashboard shows (`user_id` null — see the migration that added it).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }
}
