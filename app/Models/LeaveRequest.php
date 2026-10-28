<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time off a crew member asked for, from the first day to the last (both included).
 *
 * It is a request until a manager decides: `approved` makes the person unavailable on those
 * days, `rejected` and `cancelled` change nothing. The company is written on the row and
 * always asked for explicitly — a request is read by managers and by the person, never through
 * the signed-in company alone.
 */
class LeaveRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, string> */
    public const TYPES = [
        'vacation' => 'Vacation',
        'sick' => 'Sick leave',
        'personal' => 'Personal',
        'unpaid' => 'Unpaid',
        'other' => 'Other',
    ];

    /** The longest single request, in days. Longer absences are talked through, not tapped in. */
    public const MAX_DAYS = 90;

    protected $fillable = [
        'company_id', 'user_id', 'type', 'start_date', 'end_date', 'reason',
        'status', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** How many days it covers, first and last included. */
    public function days(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    /**
     * One company's requests; a person with no company sees only those with none.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeOfCompany(Builder $query, ?int $companyId): Builder
    {
        return $companyId === null
            ? $query->whereNull('company_id')
            : $query->where('company_id', $companyId);
    }

    /**
     * Requests that still hold the days: waiting for a decision, or granted.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeHolding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    /**
     * Requests that touch any day of `$from`..`$to` (both included, `Y-m-d`).
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('start_date', '<=', $to)->where('end_date', '>=', $from);
    }
}
