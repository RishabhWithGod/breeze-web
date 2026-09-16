<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One technician's GPS check-in/check-out on one job, for one day.
 *
 * Mirrors Breeze-Electric's `JobSiteAttendance` field for field — see
 * `docs/mobile-attendance-api-contract.md` in that repo — so the API layer
 * translating between the two never needs to invent a mapping.
 */
class JobAttendance extends Model
{
    public const STATUS_CHECKED_IN = 'checkedIn';

    public const STATUS_CHECKED_OUT = 'checkedOut';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_AUTOMATIC = 'automatic';

    public const METHOD_PHOTO = 'photo';

    protected $fillable = [
        'job_id',
        'user_id',
        'date',
        'status',
        'check_in_at',
        'check_in_lat',
        'check_in_lng',
        'check_in_accuracy',
        'check_in_distance_meters',
        'check_in_method',
        'check_in_photo_path',
        'check_out_at',
        'check_out_lat',
        'check_out_lng',
        'check_out_accuracy',
        'check_out_distance_meters',
        'check_out_method',
        'banked_seconds',
        'client_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_in_lat' => 'decimal:7',
            'check_in_lng' => 'decimal:7',
            'check_in_accuracy' => 'decimal:2',
            'check_in_distance_meters' => 'decimal:2',
            'check_out_at' => 'datetime',
            'check_out_lat' => 'decimal:7',
            'check_out_lng' => 'decimal:7',
            'check_out_accuracy' => 'decimal:2',
            'check_out_distance_meters' => 'decimal:2',
            'banked_seconds' => 'integer',
        ];
    }

    public function isCheckedIn(): bool
    {
        return $this->status === self::STATUS_CHECKED_IN;
    }

    /**
     * Total time on this job today, across every check-in/check-out cycle —
     * the same accumulation `JobSiteAttendance.workingDuration` does
     * on-device: [banked_seconds] plus whatever the current session (if
     * still open) has added since [check_in_at].
     */
    public function workingSeconds(): int
    {
        if ($this->check_in_at === null) {
            return $this->banked_seconds;
        }
        $end = $this->check_out_at ?? now();
        // Plain timestamp subtraction rather than `diffInSeconds` — Carbon's
        // sign convention for the non-absolute form is easy to get backwards,
        // and this is unambiguous regardless.
        $session = $end->getTimestamp() - $this->check_in_at->getTimestamp();

        return $this->banked_seconds + max(0, $session);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
