<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    protected $table = 'app_notifications';

    protected $fillable = ['user_id', 'type', 'title', 'detail', 'link', 'data', 'read_at'];

    /**
     * Every notification `type` grouped under the broad category the
     * Notification Center's "Filter by Type" control offers. A prefix match
     * keeps this list short — a new `task-*` reason never needs an entry here
     * unless it belongs somewhere other than its prefix's usual category.
     *
     * @var array<string, string>
     */
    public const CATEGORY_PREFIXES = [
        'job-' => 'jobs',
        'task-' => 'tasks',
        'estimate-' => 'estimates',
        'review-' => 'estimates',
        'takeoff-' => 'ai-takeoff',
        'time-entry-' => 'time-tracking',
        'document-' => 'documents',
        'invoice-' => 'billing',
        'payment-' => 'billing',
        'security-' => 'security',
        'breeze-bucks-' => 'breeze-bucks',
        'technician-' => 'teams',
    ];

    /**
     * Exact `type` values that categorise differently than their prefix would
     * suggest — checked before {@see CATEGORY_PREFIXES}. A reschedule/delay is
     * a Scheduling event even though its type is `task-*`; a cost overrun is
     * Job Costing even though its type is `job-*`.
     *
     * @var array<string, string>
     */
    public const CATEGORY_OVERRIDES = [
        'job-cost-overrun' => 'job-costing',
        'task-rescheduled' => 'scheduling',
        'task-delayed' => 'scheduling',
    ];

    public const CATEGORY_LABELS = [
        'jobs' => 'Jobs',
        'tasks' => 'Tasks',
        'estimates' => 'Estimates',
        'scheduling' => 'Scheduling',
        'ai-takeoff' => 'AI Takeoff',
        'time-tracking' => 'Time Tracking',
        'documents' => 'Documents',
        'billing' => 'Billing',
        'job-costing' => 'Job Costing',
        'security' => 'Security',
        'breeze-bucks' => 'Breeze Bucks',
        'teams' => 'Teams',
        'general' => 'General',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'data' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    /** Mirrors {@see categoryFor()}'s precedence exactly, so a row filters into the same category it displays under. */
    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        if (blank($category) || $category === 'all') {
            return $query;
        }

        $overriddenToThis = array_keys(array_filter(self::CATEGORY_OVERRIDES, fn (string $c) => $c === $category));
        $prefix = array_search($category, self::CATEGORY_PREFIXES, true);

        // No prefix owns this category at all — e.g. Scheduling and Job
        // Costing only exist via an exact-type override.
        if ($prefix === false) {
            return $query->whereIn('type', $overriddenToThis);
        }

        $overriddenAway = array_keys(array_filter(self::CATEGORY_OVERRIDES, fn (string $c) => $c !== $category));

        return $query->where(function (Builder $query) use ($prefix, $overriddenToThis) {
            $query->where('type', 'like', "{$prefix}%");
            foreach ($overriddenToThis as $type) {
                $query->orWhere('type', $type);
            }
        })->when($overriddenAway !== [], fn (Builder $q) => $q->whereNotIn('type', $overriddenAway));
    }

    /** "job-cost-overrun" → "job-costing"; "task-assigned" → "tasks"; unknown types fall back to "general". */
    public static function categoryFor(string $type): string
    {
        if (isset(self::CATEGORY_OVERRIDES[$type])) {
            return self::CATEGORY_OVERRIDES[$type];
        }

        foreach (self::CATEGORY_PREFIXES as $prefix => $category) {
            if (str_starts_with($type, $prefix)) {
                return $category;
            }
        }

        return 'general';
    }
}
