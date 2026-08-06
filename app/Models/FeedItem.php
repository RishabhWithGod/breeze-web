<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FeedItem extends Model
{
    public const DASHBOARD_ACTIVITY = 'dashboard_activity';

    public const DASHBOARD_NOTIFICATIONS = 'dashboard_notifications';

    public const DASHBOARD_SCHEDULE = 'dashboard_schedule';

    public const HISTORY_ACTIVITY = 'history_activity';

    protected $fillable = ['scope', 'segments', 'detail', 'meta', 'icon', 'tile', 'position'];

    protected function casts(): array
    {
        return ['segments' => 'array'];
    }

    public function scopeScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope)->orderBy('position');
    }
}
