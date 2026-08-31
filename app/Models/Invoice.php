<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A client invoice: a header, its line items, and a small real payment fact
 * (`paid_amount`/`paid_at`) — this schema has no payments ledger, so that is
 * the entire "payment history" there is to show.
 *
 * `status` is the workflow a person actually drives: draft → sent → paid.
 * "Overdue" is never one of those three — it is true exactly when a `sent`
 * invoice's `due_date` has passed with money still owed, so it is computed by
 * `displayStatus()`/`isOverdue()` rather than stored, and can never go stale.
 */
class Invoice extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PAID = 'paid';

    /** Real, stored workflow states. "Overdue" is derived, never stored — see class docblock. */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PAID,
    ];

    /** Every state a screen may filter or display by, including the derived one. */
    public const DISPLAY_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
        self::STATUS_PAID,
        'overdue',
    ];

    public const SORTS = ['date-desc', 'date-asc', 'amount-desc', 'amount-asc', 'number-asc'];

    protected $fillable = [
        'invoice_number',
        'job_id',
        'estimate_id',
        'client',
        'invoice_date',
        'due_date',
        'subtotal',
        'tax_pct',
        'tax_total',
        'total',
        'paid_amount',
        'status',
        'sent_at',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /* ---------------------------------------------------------------- Relations */

    /** @return BelongsTo<Job, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /** @return BelongsTo<Estimate, $this> */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->latest('occurred_at');
    }

    /* ------------------------------------------------------------------ Derived */

    /** The outstanding balance — never stored, always `total - paid_amount`. */
    public function outstanding(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    /** True once a sent invoice's due date has passed with a balance still owed. */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_SENT
            && $this->due_date !== null
            && $this->due_date->isPast()
            && $this->outstanding() > 0;
    }

    /** What a screen should actually show: the stored status, or "overdue" over it. */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status;
    }

    /** Only a draft or sent invoice may still be changed or removed — paid history stays put. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SENT], true);
    }

    /**
     * Re-sums the line items and re-applies tax.
     *
     * `total` never drifts from the lines: every item write recomputes it,
     * the same convention `Estimate::recalculateTotals()` already uses.
     */
    public function recalculateTotals(): void
    {
        $subtotal = round((float) $this->items()->sum('total'), 2);
        $tax = round($subtotal * ((float) $this->tax_pct / 100), 2);
        $total = round($subtotal + $tax, 2);

        $this->update([
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total' => $total,
        ]);
    }

    /* ------------------------------------------------------------------ Scopes */

    /** Matches an invoice number or client. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($term) {
            $query->where('invoice_number', 'like', "%{$term}%")
                ->orWhere('client', 'like', "%{$term}%");
        });
    }

    /**
     * Matches the derived display status, "overdue" included — done in SQL so
     * pagination counts stay correct rather than filtering a page after the fact.
     */
    public function scopeDisplayStatus(Builder $query, string $status): Builder
    {
        if ($status === 'overdue') {
            return $query->where('status', self::STATUS_SENT)
                ->whereNotNull('due_date')
                ->where('due_date', '<', Carbon::today())
                ->whereColumn('paid_amount', '<', 'total');
        }

        return $query->where('status', $status)
            ->where(fn (Builder $q) => $q
                ->where('status', '!=', self::STATUS_SENT)
                ->orWhere('due_date', '>=', Carbon::today())
                ->orWhereNull('due_date')
                ->orWhereColumn('paid_amount', '>=', 'total'));
    }

    /** Inclusive date window; either bound may be omitted. */
    public function scopeIssuedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $query) => $query->whereDate('invoice_date', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('invoice_date', '<=', $to));
    }

    /** Inclusive amount window on the invoice total; either bound may be omitted. */
    public function scopeAmountBetween(Builder $query, ?float $min, ?float $max): Builder
    {
        return $query
            ->when($min !== null, fn (Builder $query) => $query->where('total', '>=', $min))
            ->when($max !== null, fn (Builder $query) => $query->where('total', '<=', $max));
    }

    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'date-asc' => $query->orderBy('invoice_date')->orderBy('id'),
            'amount-desc' => $query->orderByDesc('total'),
            'amount-asc' => $query->orderBy('total'),
            'number-asc' => $query->orderBy('invoice_number'),
            default => $query->orderByDesc('invoice_date')->orderByDesc('id'),
        };
    }

    /**
     * Next reference in the INV-#### series, continuing past soft-deleted rows
     * so a restored invoice can never collide with a newly created one — the
     * same pattern `Estimate::nextNumber()` uses. `invoice_number` is also a
     * unique DB column, so a genuine race still cannot produce a duplicate: the
     * loser's insert fails outright instead of silently succeeding.
     */
    public static function nextNumber(): string
    {
        $integerType = match (static::query()->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'SIGNED',
            default => 'INTEGER',
        };

        $highest = (int) static::withTrashed()
            ->selectRaw("MAX(CAST(SUBSTR(invoice_number, 5) AS {$integerType})) AS seq")
            ->value('seq');

        return 'INV-'.max($highest + 1, 1001);
    }
}
