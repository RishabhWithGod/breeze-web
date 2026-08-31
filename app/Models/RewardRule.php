<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configurable point values for real business events. If a row for an
 * event type doesn't exist, or exists but is disabled, that event awards
 * nothing — a listener never hard-codes a point value itself.
 */
class RewardRule extends Model
{
    public const TIME_ENTRY_APPROVED = 'time_entry_approved';

    public const AI_TAKEOFF_COMPLETED = 'ai_takeoff_completed';

    public const JOB_TASK_COMPLETED = 'job_task_completed';

    public const EVENT_LABELS = [
        self::TIME_ENTRY_APPROVED => 'Time entry approved',
        self::AI_TAKEOFF_COMPLETED => 'AI Takeoff completed',
        self::JOB_TASK_COMPLETED => 'Job task completed',
    ];

    protected $fillable = ['event_type', 'points', 'enabled', 'description', 'role_restriction'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'role_restriction' => 'array',
        ];
    }

    /** True when the given role qualifies — no restriction means everyone qualifies. */
    public function allowsRole(?string $role): bool
    {
        if (blank($this->role_restriction)) {
            return true;
        }

        $normalized = mb_strtolower(trim((string) $role));

        return in_array($normalized, array_map(fn ($r) => mb_strtolower(trim($r)), $this->role_restriction), true);
    }

    /** Ensures every configured event type has a row — first-run defaults only; the DB row is what actually governs behaviour after that. */
    public static function ensureDefaults(): void
    {
        foreach (config('breeze_bucks.rules') as $eventType => $defaults) {
            static::firstOrCreate(['event_type' => $eventType], [
                'points' => $defaults['points'],
                'description' => $defaults['description'],
                'enabled' => true,
            ]);
        }
    }
}
